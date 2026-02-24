<?php

namespace App\DTOs;

readonly class FieldInfo
{
    public function __construct(
        public string $name,
        public string $component,
        public bool $isNumeric = false,
        public bool $isRequired = false,
        public ?string $relationModel = null,
        public ?array $options = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            component: $data['component'],
            isNumeric: $data['isNumeric'] ?? false,
            isRequired: $data['isRequired'] ?? false,
            relationModel: $data['relationModel'] ?? null,
            options: $data['options'] ?? null,
        );
    }
}
