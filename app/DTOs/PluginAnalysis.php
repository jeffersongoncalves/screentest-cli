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
        public array $panelIds = [],
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
            panelIds: $data['panelIds'] ?? [],
        );
    }
}
