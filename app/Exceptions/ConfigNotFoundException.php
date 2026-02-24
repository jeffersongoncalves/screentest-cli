<?php

declare(strict_types=1);

namespace App\Exceptions;

class ConfigNotFoundException extends \RuntimeException
{
    public static function atPath(string $path): self
    {
        return new self("Configuration file not found at: {$path}");
    }
}
