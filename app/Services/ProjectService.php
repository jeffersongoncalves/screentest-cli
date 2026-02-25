<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PluginRegistration;
use App\DTOs\ScreentestConfig;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;

class ProjectService
{
    public function __construct(
        protected ProcessService $process,
    ) {}

    public function create(ScreentestConfig $config): string
    {
        $tempDir = str_replace('\\', '/', config('screentest.temp_directory'));

        if (File::isDirectory($tempDir)) {
            // Use system rm on Windows — File::deleteDirectory can fail with symlinks
            if (PHP_OS_FAMILY === 'Windows') {
                $this->process->run('rmdir /s /q "'.str_replace('/', '\\', $tempDir).'"');
            } else {
                $this->process->run("rm -rf {$tempDir}");
            }

            // Fallback if still exists
            if (File::isDirectory($tempDir)) {
                File::deleteDirectory($tempDir);
            }
        }

        $this->process->composerOrFail(
            "create-project {$config->filakit->kit} {$tempDir} --no-interaction --prefer-dist",
            timeout: 600,
        );

        return $tempDir;
    }

    public function installPlugin(ScreentestConfig $config, string $projectPath, string $pluginPath): void
    {
        $composerJsonPath = $projectPath.'/composer.json';
        $composerJson = json_decode(File::get($composerJsonPath), true);

        $composerJson['repositories'] ??= [];
        $composerJson['repositories'][] = [
            'type' => 'path',
            'url' => $pluginPath,
            'options' => [
                'symlink' => true,
            ],
        ];

        File::put(
            $composerJsonPath,
            json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->process->composerOrFail(
            "require {$config->plugin->package} @dev --no-interaction",
            $projectPath,
            timeout: 300,
        );

        foreach ($config->install->extraPackages as $package) {
            $this->process->composerOrFail(
                "require {$package} --no-interaction",
                $projectPath,
                timeout: 300,
            );
        }
    }

    public function registerPlugins(ScreentestConfig $config, string $projectPath): void
    {
        foreach ($config->install->plugins as $plugin) {
            $this->registerPlugin($plugin, $projectPath);
        }
    }

    public function publishAssets(ScreentestConfig $config, string $projectPath): void
    {
        foreach ($config->install->publish as $tag) {
            $this->process->runOrFail(
                $this->process->phpBinary()." artisan vendor:publish --tag={$tag}",
                $projectPath,
            );
        }
    }

    public function runPostInstallCommands(ScreentestConfig $config, string $projectPath): void
    {
        foreach ($config->install->postInstallCommands as $command) {
            $this->process->runOrFail(
                $this->process->phpBinary()." artisan {$command}",
                $projectPath,
            );
        }
    }

    public function buildAssets(string $projectPath): void
    {
        $this->process->runOrFail(
            $this->process->pnpmBinary().' install',
            $projectPath,
            timeout: 300,
        );

        $this->process->runOrFail(
            $this->process->pnpmBinary().' build',
            $projectPath,
            timeout: 300,
        );
    }

    /**
     * @return resource|InvokedProcess
     */
    public function startServer(string $projectPath): mixed
    {
        $host = config('screentest.server.host', '127.0.0.1');
        $port = config('screentest.server.port', 8787);
        $timeout = config('screentest.server.startup_timeout', 30);

        $phpBinary = $this->process->phpBinary();
        $command = "{$phpBinary} artisan serve --host={$host} --port={$port}";

        // Use proc_open for reliable background process on Windows
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $projectPath,
        );

        if (! is_resource($process)) {
            throw new \RuntimeException("Failed to start server: {$command}");
        }

        // Make pipes non-blocking so we don't hang
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->waitForServer($host, $port, $timeout);

        return $process;
    }

    public function cleanup(string $projectPath): void
    {
        if (! File::isDirectory($projectPath)) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->process->run('rmdir /s /q "'.str_replace('/', '\\', $projectPath).'"');
        } else {
            $this->process->run("rm -rf {$projectPath}");
        }

        // Fallback
        if (File::isDirectory($projectPath)) {
            File::deleteDirectory($projectPath);
        }
    }

    protected function registerPlugin(PluginRegistration $plugin, string $projectPath): void
    {
        $providerPath = $this->findPanelProvider($plugin->panel, $projectPath);

        if (! $providerPath) {
            throw new \RuntimeException("Panel provider not found for panel: {$plugin->panel}");
        }

        $content = File::get($providerPath);

        $shortClass = class_basename($plugin->class);
        $useStatement = "use {$plugin->class};";

        // Add use statement
        if (! str_contains($content, $useStatement)) {
            $content = preg_replace(
                '/(namespace\s+[^;]+;\s*\n)/',
                "$1\n{$useStatement}\n",
                $content,
            );
        }

        $pluginCall = "->plugin({$shortClass}::make())";

        // Skip if already registered
        if (str_contains($content, $pluginCall)) {
            File::put($providerPath, $content);

            return;
        }

        // Strategy: insert ->plugin() before ->middleware() or ->authMiddleware()
        // These are safe anchor points that appear after the panel builder chain setup
        $anchors = [
            '->middleware(',
            '->authMiddleware(',
            '->pages([',
            '->widgets([',
        ];

        $inserted = false;

        foreach ($anchors as $anchor) {
            $pos = strpos($content, $anchor);
            if ($pos !== false) {
                // Find the start of this line to get indentation
                $lineStart = strrpos(substr($content, 0, $pos), "\n");
                $lineStart = $lineStart !== false ? $lineStart + 1 : 0;
                $indent = str_repeat(' ', $pos - $lineStart);

                $content = substr($content, 0, $pos)
                    .$pluginCall."\n"
                    .$indent
                    .substr($content, $pos);

                $inserted = true;

                break;
            }
        }

        // Fallback: insert before the last semicolon in the panel method
        if (! $inserted) {
            $content = preg_replace(
                '/(return\s+\$panel[\s\S]*?)(;\s*\})/m',
                "$1\n            {$pluginCall}$2",
                $content,
                1,
            );
        }

        File::put($providerPath, $content);
    }

    protected function findPanelProvider(string $panel, string $projectPath): ?string
    {
        $providersPath = $projectPath.'/app/Providers/Filament/';

        if (! File::isDirectory($providersPath)) {
            return null;
        }

        $files = File::files($providersPath);

        foreach ($files as $file) {
            $content = File::get($file->getPathname());

            if (str_contains($content, "->id('{$panel}')")) {
                return $file->getPathname();
            }
        }

        $expectedName = ucfirst($panel).'PanelProvider.php';
        $expectedPath = $providersPath.$expectedName;

        if (File::exists($expectedPath)) {
            return $expectedPath;
        }

        return null;
    }

    protected function waitForServer(string $host, int $port, int $timeout): void
    {
        $start = time();

        while ((time() - $start) < $timeout) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 2);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(500_000); // 500ms
        }

        throw new \RuntimeException(
            "Server failed to start within {$timeout} seconds at http://{$host}:{$port}"
        );
    }
}
