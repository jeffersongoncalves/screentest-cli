<?php

namespace App\DTOs;

readonly class PageInfo
{
    public function __construct(
        public string $class,
        public string $name,
        public string $slug,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            class: $data['class'],
            name: $data['name'],
            slug: $data['slug'],
        );
    }
}
