<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PluginRegistration;
use App\DTOs\ScreentestConfig;
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
     * @return resource
     */
    public function startServer(string $projectPath): mixed
    {
        $host = config('screentest.server.host', '127.0.0.1');
        $port = (int) config('screentest.server.port', 8787);
        $timeout = (int) config('screentest.server.startup_timeout', 30);

        // Kill any leftover process on this port from a previous run
        $this->killProcessOnPort($port);

        // Use PHP_BINARY (the actual .exe) instead of phpBinary() which may return
        // a .bat wrapper that causes issues with proc_open on Windows
        $phpBinary = $this->resolvePhpExecutable();
        $cmd = "{$phpBinary} artisan serve --host={$host} --port={$port}";

        $nullFile = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $logDir = str_replace('\\', '/', sys_get_temp_dir()).'/screentest-debug';
        @mkdir($logDir, 0777, true);
        $stdoutLog = $logDir.'/server-stdout.log';
        $stderrLog = $logDir.'/server-stderr.log';

        $descriptors = [
            0 => ['file', $nullFile, 'r'],
            1 => ['file', $stdoutLog, 'w'],
            2 => ['file', $stderrLog, 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $projectPath);

        if (! is_resource($process)) {
            throw new \RuntimeException("Failed to start server with: {$cmd}");
        }

        // Give the process a moment to start or fail
        usleep(500_000);

        $status = proc_get_status($process);

        if (! ($status['running'] ?? false)) {
            $stdout = is_file($stdoutLog) ? trim((string) file_get_contents($stdoutLog)) : '';
            $stderr = is_file($stderrLog) ? trim((string) file_get_contents($stderrLog)) : '';
            $detail = '';

            if ($stdout !== '') {
                $detail .= "\nServer stdout: {$stdout}";
            }

            if ($stderr !== '') {
                $detail .= "\nServer stderr: {$stderr}";
            }

            throw new \RuntimeException(
                "Server process exited immediately (exit code: {$status['exitcode']}).{$detail}\nCommand: {$cmd}\nCwd: {$projectPath}"
            );
        }

        $this->waitForServer($host, $port, $timeout, $stdoutLog, $stderrLog);

        return $process;
    }

    public function stopServer(mixed $serverProcess): void
    {
        $port = (int) config('screentest.server.port', 8787);

        try {
            if (is_resource($serverProcess)) {
                $status = proc_get_status($serverProcess);

                if ($status['running'] ?? false) {
                    proc_terminate($serverProcess);
                }

                proc_close($serverProcess);
            }
        } catch (\Throwable) {
            // Ignore
        }

        // Always kill by port as fallback (proc_terminate may not kill child processes on Windows)
        $this->killProcessOnPort($port);
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

    protected function killProcessOnPort(int $port): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("netstat -ano | findstr :{$port}", $output);

            $pids = [];

            foreach ($output ?? [] as $line) {
                if (preg_match('/LISTENING\s+(\d+)/', $line, $matches)) {
                    $pids[(int) $matches[1]] = true;
                }
            }

            foreach (array_keys($pids) as $pid) {
                if ($pid > 0) {
                    exec("taskkill /F /T /PID {$pid} 2>NUL");
                }
            }
        } else {
            exec("lsof -ti:{$port} 2>/dev/null | xargs kill -9 2>/dev/null");
        }
    }

    protected function resolvePhpExecutable(): string
    {
        // On Windows, .bat wrappers cause issues with proc_open (quoting hell with cmd.exe /c).
        // Use PHP_BINARY which is always the actual .exe path of the running PHP process.
        if (PHP_OS_FAMILY === 'Windows') {
            $binary = PHP_BINARY;

            // Quote if path contains spaces
            if (str_contains($binary, ' ')) {
                return '"'.$binary.'"';
            }

            return $binary;
        }

        return $this->process->phpBinary();
    }

    protected function waitForServer(string $host, int $port, int $timeout, ?string $stdoutLog = null, ?string $stderrLog = null): void
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

        $errorDetail = '';

        if ($stdoutLog && file_exists($stdoutLog)) {
            $stdout = trim((string) file_get_contents($stdoutLog));

            if ($stdout !== '') {
                $errorDetail .= "\nServer stdout: {$stdout}";
            }
        }

        if ($stderrLog && file_exists($stderrLog)) {
            $stderr = trim((string) file_get_contents($stderrLog));

            if ($stderr !== '') {
                $errorDetail .= "\nServer stderr: {$stderr}";
            }
        }

        throw new \RuntimeException(
            "Server failed to start within {$timeout} seconds at http://{$host}:{$port}{$errorDetail}"
        );
    }
}
