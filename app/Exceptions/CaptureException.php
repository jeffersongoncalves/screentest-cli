<?php

declare(strict_types=1);

namespace App\Exceptions;

class CaptureException extends \RuntimeException
{
    public static function screenshotFailed(string $name, string $reason): self
    {
        return new self("Screenshot capture failed for '{$name}': {$reason}");
    }
}
