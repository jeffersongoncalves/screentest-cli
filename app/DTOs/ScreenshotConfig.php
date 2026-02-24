<?php

namespace App\DTOs;

readonly class ScreenshotConfig
{
    public function __construct(
        public string $name,
        public string $url,
        public string $selector = 'body',
        public ?ViewportConfig $viewport = null,
        public array $before = [],
        public ?CropConfig $crop = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            url: $data['url'],
            selector: $data['selector'] ?? 'body',
            viewport: isset($data['viewport']) ? ViewportConfig::fromArray($data['viewport']) : null,
            before: isset($data['before']) ? array_map(
                fn (array $action) => BeforeAction::fromArray($action),
                $data['before'],
            ) : [],
            crop: isset($data['crop']) ? CropConfig::fromArray($data['crop']) : null,
        );
    }
}
