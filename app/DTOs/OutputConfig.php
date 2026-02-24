<?php

namespace App\DTOs;

use App\Enums\ImageFormat;
use App\Enums\Theme;

readonly class OutputConfig
{
    public function __construct(
        public string $directory = 'screenshots',
        public array $themes = [Theme::Light, Theme::Dark],
        public ImageFormat $format = ImageFormat::Png,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            directory: $data['directory'] ?? 'screenshots',
            themes: isset($data['themes']) ? array_map(
                fn (string $theme) => Theme::from($theme),
                $data['themes'],
            ) : [Theme::Light, Theme::Dark],
            format: isset($data['format']) ? ImageFormat::from($data['format']) : ImageFormat::Png,
        );
    }
}
