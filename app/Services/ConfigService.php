<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ScreentestConfig;
use App\Exceptions\ConfigNotFoundException;
use App\Exceptions\ConfigValidationException;
use JeffersonGoncalves\LaravelZero\JsonConfig\JsonConfigService;
use JeffersonGoncalves\LaravelZero\JsonConfig\Scopes\PerProjectScope;

class ConfigService
{
    public function load(string $pluginPath): ScreentestConfig
    {
        $config = $this->config($pluginPath);
        $configPath = $config->path();

        if (! file_exists($configPath)) {
            throw ConfigNotFoundException::atPath($configPath);
        }

        $raw = (string) file_get_contents($configPath);

        if (trim($raw) !== '' && json_decode($raw, true) === null && json_last_error() !== JSON_ERROR_NONE) {
            throw ConfigValidationException::withErrors([
                'Invalid JSON: '.json_last_error_msg(),
            ]);
        }

        $data = $config->all();

        $this->validate($data);

        return ScreentestConfig::fromArray($data);
    }

    public function save(string $pluginPath, array $data): void
    {
        $config = $this->config($pluginPath);

        foreach (array_keys($config->all()) as $existing) {
            $config->forget((string) $existing);
        }

        foreach ($data as $key => $value) {
            $config->set((string) $key, $value);
        }
    }

    public function exists(string $pluginPath): bool
    {
        return file_exists($this->config($pluginPath)->path());
    }

    protected function config(string $pluginPath): JsonConfigService
    {
        return new JsonConfigService(new PerProjectScope($pluginPath, 'screentest.json'));
    }

    protected function validate(array $data): void
    {
        $errors = [];

        if (! isset($data['plugin'])) {
            $errors[] = 'Missing required field: plugin';
        } else {
            if (! isset($data['plugin']['name'])) {
                $errors[] = 'Missing required field: plugin.name';
            }
            if (! isset($data['plugin']['package'])) {
                $errors[] = 'Missing required field: plugin.package';
            }
        }

        if (isset($data['screenshots']) && ! is_array($data['screenshots'])) {
            $errors[] = 'Field "screenshots" must be an array';
        }

        if (isset($data['screenshots'])) {
            foreach ($data['screenshots'] as $i => $screenshot) {
                if (! isset($screenshot['name'])) {
                    $errors[] = "Missing required field: screenshots[{$i}].name";
                }
                if (! isset($screenshot['url'])) {
                    $errors[] = "Missing required field: screenshots[{$i}].url";
                }
            }
        }

        if (isset($data['output']['themes'])) {
            $validThemes = ['light', 'dark'];
            foreach ($data['output']['themes'] as $theme) {
                if (! in_array($theme, $validThemes, true)) {
                    $errors[] = "Invalid theme: {$theme}. Must be one of: ".implode(', ', $validThemes);
                }
            }
        }

        if (isset($data['output']['format'])) {
            $validFormats = ['png', 'jpg', 'webp'];
            if (! in_array($data['output']['format'], $validFormats, true)) {
                $errors[] = "Invalid format: {$data['output']['format']}. Must be one of: ".implode(', ', $validFormats);
            }
        }

        if ($errors) {
            throw ConfigValidationException::withErrors($errors);
        }
    }
}
