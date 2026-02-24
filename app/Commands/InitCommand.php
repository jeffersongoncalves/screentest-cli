<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\ResolvesPluginPath;
use App\DTOs\PluginAnalysis;
use App\Services\ConfigService;
use App\Services\PluginAnalyzerService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

class InitCommand extends Command
{
    use ResolvesPluginPath;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'init
        {--path= : Plugin directory path}
        {--force : Overwrite existing config}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Initialize screentest.json for a Filament plugin';

    /**
     * Execute the console command.
     */
    public function handle(PluginAnalyzerService $analyzer, ConfigService $configService): int
    {
        $pluginPath = $this->resolvePluginPath($this->option('path'));

        // Check if config already exists
        if ($configService->exists($pluginPath) && ! $this->option('force')) {
            $this->error('screentest.json already exists. Use --force to overwrite.');

            return self::FAILURE;
        }

        // Check composer.json exists
        if (! $this->hasComposerJson($pluginPath)) {
            $this->error('No composer.json found. Are you in a Filament plugin directory?');

            return self::FAILURE;
        }

        // Analyze the plugin
        $analysis = null;

        $this->task('Analyzing plugin structure', function () use ($analyzer, $pluginPath, &$analysis) {
            $analysis = $analyzer->analyze($pluginPath);

            return true;
        });

        if (! $analysis instanceof PluginAnalysis) {
            $this->error('Failed to analyze plugin structure.');

            return self::FAILURE;
        }

        // Interactive refinement with Laravel Prompts
        $pluginName = text(
            label: 'Plugin name',
            default: $this->guessPluginName($analysis),
            required: true,
        );

        $package = text(
            label: 'Package name',
            default: $analysis->package,
            required: true,
        );

        // Build screenshot options from detected resources
        $screenshotOptions = $this->buildScreenshotOptions($analysis);
        $selectedScreenshots = [];

        if (! empty($screenshotOptions)) {
            $selectedScreenshots = multiselect(
                label: 'Which screenshots should be generated?',
                options: $screenshotOptions,
                default: array_keys($screenshotOptions),
            );
        }

        $updateReadme = confirm(
            label: 'Update README.md with screenshots?',
            default: true,
        );

        // Determine Filakit based on detected Filament version
        $filakitKit = match ($analysis->filamentVersion) {
            \App\Enums\FilamentVersion::V3 => 'jeffersongoncalves/basev3',
            \App\Enums\FilamentVersion::V4 => 'jeffersongoncalves/basev4',
            \App\Enums\FilamentVersion::V5 => 'jeffersongoncalves/basev5',
            default => 'jeffersongoncalves/basev5',
        };

        // Build the config array
        $config = [
            'plugin' => [
                'name' => $pluginName,
                'package' => $package,
            ],
            'filakit' => [
                'kit' => $filakitKit,
            ],
            'install' => [
                'extra_packages' => [],
                'plugins' => [
                    [
                        'class' => $analysis->pluginClass,
                        'panel' => 'admin',
                    ],
                ],
                'publish' => [],
                'post_install_commands' => ['migrate'],
            ],
            'seed' => [
                'auto_detect' => true,
                'user' => [
                    'email' => 'admin@example.com',
                    'password' => 'password',
                    'name' => 'Admin User',
                ],
                'models' => $this->buildModelSeedConfig($analysis),
            ],
            'screenshots' => $this->buildScreenshotsConfig($selectedScreenshots, $analysis),
            'output' => [
                'directory' => 'screenshots',
                'themes' => ['light', 'dark'],
                'format' => 'png',
            ],
            'readme' => [
                'update' => $updateReadme,
                'section_marker' => '<!-- SCREENSHOTS -->',
                'template' => 'table',
            ],
        ];

        // Save the config
        $this->task('Saving screentest.json', function () use ($configService, $pluginPath, $config) {
            $configService->save($pluginPath, $config);

            return true;
        });

        $this->newLine();
        $this->info('Configuration saved to: '.$pluginPath.'/screentest.json');
        $this->newLine();

        if (! empty($analysis->resources)) {
            $this->info('Detected '.count($analysis->resources).' resource(s):');
            foreach ($analysis->resources as $resource) {
                $this->line('  - '.$resource->modelShortName.' ('.count($resource->fields).' fields)');
            }
            $this->newLine();
        }

        $this->info('Run "screentest capture" to generate screenshots.');

        return self::SUCCESS;
    }

    /**
     * Guess a human-readable plugin name from the analysis.
     */
    private function guessPluginName(PluginAnalysis $analysis): string
    {
        // Try to extract name from the plugin class (e.g., "MyPlugin" -> "My Plugin")
        $className = class_basename($analysis->pluginClass);
        $className = str_replace('Plugin', '', $className);

        if (! empty($className)) {
            // Convert PascalCase to words with spaces
            return trim(preg_replace('/([a-z])([A-Z])/', '$1 $2', $className));
        }

        // Fall back to package name
        $parts = explode('/', $analysis->package);

        return ucwords(str_replace('-', ' ', end($parts)));
    }

    /**
     * Build screenshot option choices from detected resources.
     *
     * @return array<string, string>
     */
    private function buildScreenshotOptions(PluginAnalysis $analysis): array
    {
        $options = [];

        foreach ($analysis->resources as $resource) {
            $name = $resource->modelShortName;
            $options["{$name}-list"] = "{$name} - List Page";
            $options["{$name}-create"] = "{$name} - Create Page";
            $options["{$name}-edit"] = "{$name} - Edit Page";
        }

        return $options;
    }

    /**
     * Build the screenshots config array from the selected options.
     *
     * @return array<int, array<string, string>>
     */
    private function buildScreenshotsConfig(array $selectedKeys, PluginAnalysis $analysis): array
    {
        $screenshots = [];

        foreach ($analysis->resources as $resource) {
            $name = $resource->modelShortName;
            $slug = strtolower(str_replace(' ', '-', preg_replace('/([a-z])([A-Z])/', '$1-$2', $name)));
            $pluralSlug = $slug.'s';

            if (in_array("{$name}-list", $selectedKeys, true)) {
                $screenshots[] = [
                    'name' => strtolower($name).'-list',
                    'url' => "/admin/{$pluralSlug}",
                ];
            }

            if (in_array("{$name}-create", $selectedKeys, true)) {
                $screenshots[] = [
                    'name' => strtolower($name).'-create',
                    'url' => "/admin/{$pluralSlug}/create",
                ];
            }

            if (in_array("{$name}-edit", $selectedKeys, true)) {
                $screenshots[] = [
                    'name' => strtolower($name).'-edit',
                    'url' => "/admin/{$pluralSlug}/1/edit",
                ];
            }
        }

        return $screenshots;
    }

    /**
     * Build model seed configuration from detected resources.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildModelSeedConfig(PluginAnalysis $analysis): array
    {
        $models = [];

        foreach ($analysis->resources as $resource) {
            $models[] = [
                'model' => $resource->model,
                'count' => 10,
            ];
        }

        return $models;
    }
}
