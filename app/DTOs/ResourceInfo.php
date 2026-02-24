<?php

namespace App\DTOs;

readonly class ResourceInfo
{
    public function __construct(
        public string $class,
        public string $model,
        public string $modelShortName,
        public array $fields = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            class: $data['class'],
            model: $data['model'],
            modelShortName: $data['modelShortName'],
            fields: isset($data['fields']) ? array_map(
                fn (array $field) => FieldInfo::fromArray($field),
                $data['fields'],
            ) : [],
        );
    }
}
