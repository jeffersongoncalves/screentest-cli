<?php

namespace App\DTOs;

readonly class FilakitConfig
{
    public function __construct(
        public string $kit = 'filakitphp/basev5',
        public ?string $version = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            kit: $data['kit'] ?? 'filakitphp/basev5',
            version: $data['version'] ?? null,
        );
    }
}
