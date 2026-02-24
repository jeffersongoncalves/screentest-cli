<?php

namespace App\DTOs;

readonly class PluginConfig
{
    public function __construct(
        public string $name,
        public string $package,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            package: $data['package'],
        );
    }
}
