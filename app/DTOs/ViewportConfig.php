<?php

namespace App\DTOs;

readonly class ViewportConfig
{
    public function __construct(
        public int $width = 1920,
        public int $height = 1080,
        public int $deviceScaleFactor = 3,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            width: $data['width'] ?? 1920,
            height: $data['height'] ?? 1080,
            deviceScaleFactor: $data['deviceScaleFactor'] ?? 3,
        );
    }
}
