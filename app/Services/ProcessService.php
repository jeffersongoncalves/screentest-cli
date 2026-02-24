<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProjectSetupException;
use Illuminate\Support\Facades\Process;

class ProcessService
{
    public function php(string $arguments, ?string $cwd = null, ?int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        return $this->run($this->phpBinary().' '.$arguments, $cwd, $timeout);
    }

    public function composer(string $arguments, ?string $cwd = null, ?int $timeout = 300): \Illuminate\Contracts\Process\ProcessResult
    {
        return $this->run($this->phpBinary().' '.$this->composerBinary().' '.$arguments, $cwd, $timeout);
    }

    public function node(string $arguments, ?string $cwd = null, ?int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        return $this->run($this->nodeBinary().' '.$arguments, $cwd, $timeout);
    }

    public function pnpm(string $arguments, ?string $cwd = null, ?int $timeout = 300): \Illuminate\Contracts\Process\ProcessResult
    {
        return $this->run($this->pnpmBinary().' '.$arguments, $cwd, $timeout);
    }

    public function artisan(string $arguments, string $cwd, ?int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        return $this->php('artisan '.$arguments, $cwd, $timeout);
    }

    public function run(string $command, ?string $cwd = null, ?int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        $process = Process::timeout($timeout);

        if ($cwd) {
            $process = $process->path($cwd);
        }

        return $process->run($command);
    }

    public function runOrFail(string $command, ?string $cwd = null, ?int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        $result = $this->run($command, $cwd, $timeout);

        if (! $result->successful()) {
            throw ProjectSetupException::fromProcess($command, $result->exitCode(), $result->output().$result->errorOutput());
        }

        return $result;
    }

    public function startBackground(string $command, ?string $cwd = null): \Illuminate\Process\InvokedProcess
    {
        $process = Process::timeout(0);

        if ($cwd) {
            $process = $process->path($cwd);
        }

        return $process->start($command);
    }

    public function phpBinary(): string
    {
        return config('screentest.php_binary', 'php');
    }

    public function composerBinary(): string
    {
        return config('screentest.composer_binary', 'composer');
    }

    public function nodeBinary(): string
    {
        return config('screentest.node_binary', 'node');
    }

    public function pnpmBinary(): string
    {
        return config('screentest.pnpm_binary', 'pnpm');
    }
}
