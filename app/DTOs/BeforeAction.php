<?php

namespace App\DTOs;

use App\Enums\BeforeActionType;

readonly class BeforeAction
{
    public function __construct(
        public BeforeActionType $action,
        public ?string $selector = null,
        public ?string $value = null,
        public ?int $delay = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            action: BeforeActionType::from($data['action']),
            selector: $data['selector'] ?? null,
            value: $data['value'] ?? null,
            delay: $data['delay'] ?? null,
        );
    }
}
