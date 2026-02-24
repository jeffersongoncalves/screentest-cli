<?php

namespace App\DTOs;

readonly class PluginRegistration
{
    public function __construct(
        public string $class,
        public string $panel = 'admin',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            class: $data['class'],
            panel: $data['panel'] ?? 'admin',
        );
    }
}
