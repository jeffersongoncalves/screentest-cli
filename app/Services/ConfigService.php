<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ScreentestConfig;
use App\Exceptions\ConfigNotFoundException;
use App\Exceptions\ConfigValidationException;

class ConfigService
{
    public function load(string $pluginPath): ScreentestConfig
    {
        $configPath = $pluginPath.'/screentest.json';

        if (! file_exists($configPath)) {
            throw ConfigNotFoundException::atPath($configPath);
        }

        $json = file_get_contents($configPath);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ConfigValidationException::withErrors([
                'Invalid JSON: '.json_last_error_msg(),
            ]);
        }

        $this->validate($data);

        return ScreentestConfig::fromArray($data);
    }

    public function save(string $pluginPath, array $data): void
    {
        $configPath = $pluginPath.'/screentest.json';

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($configPath, $json."\n");
    }

    public function exists(string $pluginPath): bool
    {
        return file_exists($pluginPath.'/screentest.json');
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
