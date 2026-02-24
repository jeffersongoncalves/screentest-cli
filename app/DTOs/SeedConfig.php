<?php

namespace App\DTOs;

readonly class SeedConfig
{
    public function __construct(
        public bool $autoDetect = true,
        public UserConfig $user = new UserConfig,
        public array $models = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            autoDetect: $data['autoDetect'] ?? true,
            user: isset($data['user']) ? UserConfig::fromArray($data['user']) : new UserConfig,
            models: isset($data['models']) ? array_map(
                fn (array $model) => ModelSeedConfig::fromArray($model),
                $data['models'],
            ) : [],
        );
    }
}
