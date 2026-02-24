<?php

namespace App\DTOs;

readonly class CaptureResult
{
    public function __construct(
        public string $name,
        public string $theme,
        public string $path,
        public bool $success,
        public ?string $error = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            theme: $data['theme'],
            path: $data['path'],
            success: $data['success'],
            error: $data['error'] ?? null,
        );
    }
}
