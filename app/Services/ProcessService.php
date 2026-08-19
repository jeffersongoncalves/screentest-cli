<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProjectSetupException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

class ProcessService
{
    public function php(string $arguments, ?string $cwd = null, ?int $timeout = 120): ProcessResult
    {
        return $this->run($this->phpBinary().' '.$arguments, $cwd, $timeout);
    }

    public function composer(string $arguments, ?string $cwd = null, ?int $timeout = 300): ProcessResult
    {
        return $this->run($this->composerBinary().' '.$arguments, $cwd, $timeout);
    }

    public function composerOrFail(string $arguments, ?string $cwd = null, ?int $timeout = 300): ProcessResult
    {
        return $this->runOrFail($this->composerBinary().' '.$arguments, $cwd, $timeout);
    }

    public function node(string $arguments, ?string $cwd = null, ?int $timeout = 120): ProcessResult
    {
        return $this->run($this->nodeBinary().' '.$arguments, $cwd, $timeout);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function pnpm(string $arguments, ?string $cwd = null, ?int $timeout = 300, array $env = []): ProcessResult
    {
        return $this->run($this->pnpmBinary().' '.$arguments, $cwd, $timeout, $env);
    }

    public function artisan(string $arguments, string $cwd, ?int $timeout = 120): ProcessResult
    {
        return $this->php('artisan '.$arguments, $cwd, $timeout);
    }

    public function docker(string $arguments, ?int $timeout = 300): ProcessResult
    {
        return $this->run($this->dockerBinary().' '.$arguments, null, $timeout);
    }

    /**
     * @param  array<string, string>  $env
     */
    public function run(string $command, ?string $cwd = null, ?int $timeout = 120, array $env = []): ProcessResult
    {
        $process = Process::timeout($timeout);

        if ($cwd) {
            $process = $process->path($cwd);
        }

        if ($env !== []) {
            $process = $process->env($env);
        }

        return $process->run($command);
    }

    public function runOrFail(string $command, ?string $cwd = null, ?int $timeout = 120): ProcessResult
    {
        $result = $this->run($command, $cwd, $timeout);

        if (! $result->successful()) {
            throw ProjectSetupException::fromProcess($command, $result->exitCode(), $result->output().$result->errorOutput());
        }

        return $result;
    }

    public function startBackground(string $command, ?string $cwd = null): InvokedProcess
    {
        $process = Process::timeout(0);

        if ($cwd) {
            $process = $process->path($cwd);
        }

        return $process->start($command);
    }

    public function phpBinary(): string
    {
        $configured = config('screentest.php_binary', 'php');

        if ($configured !== 'php') {
            return $configured;
        }

        return $this->detectBinary('php', ['php.bat']) ?? 'php';
    }

    public function composerBinary(): string
    {
        $configured = config('screentest.composer_binary', 'composer');

        if ($configured !== 'composer') {
            return $configured;
        }

        return $this->detectBinary('composer', ['composer.bat']) ?? 'composer';
    }

    public function nodeBinary(): string
    {
        return config('screentest.node_binary', 'node');
    }

    public function pnpmBinary(): string
    {
        return config('screentest.pnpm_binary', 'pnpm');
    }

    public function dockerBinary(): string
    {
        return config('screentest.docker_binary', 'docker');
    }

    protected function detectBinary(string $name, array $windowsNames = []): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        // Detect Laravel Herd binaries on Windows
        $herdBinDir = str_replace('\\', '/', getenv('USERPROFILE') ?: getenv('HOME')).'/.config/herd/bin';

        if (is_dir($herdBinDir)) {
            foreach ($windowsNames as $winName) {
                $path = $herdBinDir.'/'.$winName;
                if (file_exists($path)) {
                    return '"'.str_replace('/', '\\', $path).'"';
                }
            }
        }

        // Detect from Composer global bin (for composer itself)
        if ($name === 'composer') {
            $composerGlobal = str_replace('\\', '/', getenv('USERPROFILE') ?: getenv('HOME')).'/AppData/Roaming/Composer/vendor/bin/composer';
            if (file_exists($composerGlobal)) {
                return '"'.str_replace('/', '\\', $composerGlobal).'"';
            }
        }

        return null;
    }
}
