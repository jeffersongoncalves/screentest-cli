<?php

namespace App\DTOs;

readonly class ScreentestConfig
{
    public function __construct(
        public PluginConfig $plugin,
        public FilakitConfig $filakit = new FilakitConfig,
        public InstallConfig $install = new InstallConfig,
        public SeedConfig $seed = new SeedConfig,
        public array $screenshots = [],
        public OutputConfig $output = new OutputConfig,
        public ReadmeConfig $readme = new ReadmeConfig,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            plugin: PluginConfig::fromArray($data['plugin']),
            filakit: isset($data['filakit']) ? FilakitConfig::fromArray($data['filakit']) : new FilakitConfig,
            install: isset($data['install']) ? InstallConfig::fromArray($data['install']) : new InstallConfig,
            seed: isset($data['seed']) ? SeedConfig::fromArray($data['seed']) : new SeedConfig,
            screenshots: isset($data['screenshots']) ? array_map(
                fn (array $screenshot) => ScreenshotConfig::fromArray($screenshot),
                $data['screenshots'],
            ) : [],
            output: isset($data['output']) ? OutputConfig::fromArray($data['output']) : new OutputConfig,
            readme: isset($data['readme']) ? ReadmeConfig::fromArray($data['readme']) : new ReadmeConfig,
        );
    }
}
