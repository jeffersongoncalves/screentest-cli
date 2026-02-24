<?php

namespace App\Concerns;

trait ResolvesPluginPath
{
    protected function resolvePluginPath(?string $path = null): string
    {
        $resolved = $path ? realpath($path) : getcwd();

        if (! $resolved || ! is_dir($resolved)) {
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
