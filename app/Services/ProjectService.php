<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PluginRegistration;
use App\DTOs\ScreentestConfig;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class ProjectService
{
    public function __construct(
        protected ProcessService $process,
    ) {}

    public function create(ScreentestConfig $config): string
    {
        $tempDir = str_replace('\\', '/', config('screentest.temp_directory'));

        if (File::isDirectory($tempDir)) {
            File::deleteDirectory($tempDir);
        }

        $this->process->runOrFail(
            "laravel new {$tempDir} --using={$config->filakit->kit} --no-interaction",
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

        $this->process->runOrFail(
            $this->process->phpBinary().' '.$this->process->composerBinary()." require {$config->plugin->package} @dev --no-interaction",
            $projectPath,
            timeout: 300,
        );

        foreach ($config->install->extraPackages as $package) {
            $this->process->runOrFail(
                $this->process->phpBinary().' '.$this->process->composerBinary()." require {$package} --no-interaction",
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

    public function startServer(string $projectPath): InvokedProcess
    {
        $host = config('screentest.server.host', '127.0.0.1');
        $port = config('screentest.server.port', 8787);
        $timeout = config('screentest.server.startup_timeout', 30);

        $invokedProcess = $this->process->startBackground(
            $this->process->phpBinary()." artisan serve --host={$host} --port={$port}",
            $projectPath,
        );

        $this->waitForServer($host, $port, $timeout);

        return $invokedProcess;
    }

    public function cleanup(string $projectPath): void
    {
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

        if (! str_contains($content, $useStatement)) {
            $content = preg_replace(
                '/(namespace\s+[^;]+;\s*\n)/',
                "$1\n{$useStatement}\n",
                $content,
            );
        }

        $content = preg_replace(
            '/(->discoverResources\([^)]*\))/',
            "$1\n            ->plugin({$shortClass}::make())",
            $content,
        );

        if (! str_contains($content, "->plugin({$shortClass}::make())")) {
            $content = preg_replace(
                '/(->authMiddleware\([^)]*\))/',
                "$1\n            ->plugin({$shortClass}::make())",
                $content,
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
        $url = "http://{$host}:{$port}";

        while ((time() - $start) < $timeout) {
            try {
                $response = Http::timeout(2)->get($url);

                if ($response->successful() || $response->redirect()) {
                    return;
                }
            } catch (\Exception) {
                // Server not ready yet
            }

            usleep(500_000); // 500ms
        }

        throw new \RuntimeException(
            "Server failed to start within {$timeout} seconds at {$url}"
        );
    }
}
