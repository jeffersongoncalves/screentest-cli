<?php

declare(strict_types=1);

namespace App\Exceptions;

class ProjectSetupException extends \RuntimeException
{
    public static function fromProcess(string $command, int $exitCode, string $output): self
    {
        return new self(
            "Project setup failed while running '{$command}' (exit code: {$exitCode}): {$output}"
        );
    }
}
