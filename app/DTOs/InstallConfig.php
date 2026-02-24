<?php

namespace App\DTOs;

readonly class InstallConfig
{
    public function __construct(
        public array $extraPackages = [],
        public array $plugins = [],
        public array $publish = [],
        public array $postInstallCommands = ['migrate'],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            extraPackages: $data['extra_packages'] ?? [],
            plugins: isset($data['plugins']) ? array_map(
                fn (array $plugin) => PluginRegistration::fromArray($plugin),
                $data['plugins'],
            ) : [],
            publish: $data['publish'] ?? [],
            postInstallCommands: $data['post_install_commands'] ?? ['migrate'],
        );
    }
}
