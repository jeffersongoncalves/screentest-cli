<?php

namespace App\DTOs;

readonly class ReadmeConfig
{
    public function __construct(
        public bool $update = false,
        public string $sectionMarker = '<!-- SCREENSHOTS -->',
        public string $template = 'table',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            update: $data['update'] ?? false,
            sectionMarker: $data['section_marker'] ?? '<!-- SCREENSHOTS -->',
            template: $data['template'] ?? 'table',
        );
    }
}
