<?php

namespace App\DTOs;

readonly class CropConfig
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            x: $data['x'],
            y: $data['y'],
            width: $data['width'],
            height: $data['height'],
        );
    }
}
