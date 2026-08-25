<?php

namespace App\DTOs;

readonly class ModelSeedConfig
{
    public function __construct(
        public string $model,
        public int $count = 10,
        public ?array $attributes = null,
        public bool $afterResources = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            model: $data['model'],
            count: $data['count'] ?? 10,
            attributes: $data['attributes'] ?? null,
            afterResources: $data['after_resources'] ?? false,
        );
    }
}
