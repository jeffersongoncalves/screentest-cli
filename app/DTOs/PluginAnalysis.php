<?php

namespace App\DTOs;

use App\Enums\FilamentVersion;

readonly class PluginAnalysis
{
    public function __construct(
        public string $pluginClass,
        public string $package,
        public ?FilamentVersion $filamentVersion,
        public array $resources = [],
        public array $pages = [],
        public array $panelIds = [],
        public array $publishTags = [],
        public array $envCandidates = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            pluginClass: $data['pluginClass'],
            package: $data['package'],
            filamentVersion: isset($data['filamentVersion']) ? FilamentVersion::from($data['filamentVersion']) : null,
            resources: isset($data['resources']) ? array_map(
                fn (array $resource) => ResourceInfo::fromArray($resource),
                $data['resources'],
            ) : [],
            pages: isset($data['pages']) ? array_map(
                fn (array $page) => PageInfo::fromArray($page),
                $data['pages'],
            ) : [],
            panelIds: $data['panelIds'] ?? [],
            publishTags: $data['publishTags'] ?? [],
            envCandidates: $data['envCandidates'] ?? [],
        );
    }
}
