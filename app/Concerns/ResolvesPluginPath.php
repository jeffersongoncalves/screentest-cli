<?php

namespace App\Concerns;

use JeffersonGoncalves\LaravelZero\Console\ResolvesPath;

trait ResolvesPluginPath
{
    use ResolvesPath;

    protected function resolvePluginPath(?string $path = null): string
    {
        $resolved = $this->resolvePath($path);

        if (! is_dir($resolved)) {
            throw new \RuntimeException("Plugin path does not exist: {$path}");
        }

        return $resolved;
    }

    protected function hasComposerJson(string $path): bool
    {
        return file_exists($path.'/composer.json');
    }

    protected function hasScreentestConfig(string $path): bool
    {
        return file_exists($path.'/screentest.json');
    }
}
