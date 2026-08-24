<?php

namespace App\DTOs;

readonly class ScreenshotConfig
{
    public function __construct(
        public string $name,
        public ?string $url = null,
        public ?string $route = null,
        public array $routeParams = [],
        public bool $signed = false,
        public string $selector = 'body',
        public ?ViewportConfig $viewport = null,
        public array $before = [],
        public ?CropConfig $crop = null,
        public bool $fullPage = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            url: $data['url'] ?? null,
            route: $data['route'] ?? null,
            routeParams: $data['routeParams'] ?? [],
            signed: $data['signed'] ?? false,
            selector: $data['selector'] ?? 'body',
            viewport: isset($data['viewport']) ? ViewportConfig::fromArray($data['viewport']) : null,
            before: isset($data['before']) ? array_map(
                fn (array $action) => BeforeAction::fromArray($action),
                $data['before'],
            ) : [],
            crop: isset($data['crop']) ? CropConfig::fromArray($data['crop']) : null,
            fullPage: $data['fullPage'] ?? false,
        );
    }
}
