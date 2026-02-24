<?php

declare(strict_types=1);

namespace App\Exceptions;

class ConfigValidationException extends \RuntimeException
{
    /** @var array<int, string> */
    private array $errors;

    public static function withErrors(array $errors): self
    {
        $instance = new self('Configuration validation failed: ' . implode('; ', $errors));
        $instance->errors = $errors;

        return $instance;
    }

    /** @return array<int, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
