<?php

namespace App\Concerns;

use App\DTOs\ScreentestConfig;
use App\Services\ConfigService;

trait LoadsConfig
{
    use ResolvesPluginPath;

    protected function loadConfig(?string $path = null): ScreentestConfig
    {
        $pluginPath = $this->resolvePluginPath($path);

        return app(ConfigService::class)->load($pluginPath);
    }
}
