<?php

namespace App\DTOs;

readonly class FilakitConfig
{
    public function __construct(
        public string $kit = 'jeffersongoncalves/basev5',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            kit: $data['kit'] ?? 'jeffersongoncalves/basev5',
        );
    }
}
