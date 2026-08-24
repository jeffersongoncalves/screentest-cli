<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\ResolvesPluginPath;
use App\DTOs\PluginAnalysis;
use App\DTOs\RouteInfo;
use App\Enums\FilamentVersion;
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
        {--force : Overwrite existing config}
        {--name= : Plugin name (skips prompt)}
        {--package= : Package name (skips prompt)}
        {--screenshots= : Comma-separated screenshot keys to include, or "all" (skips prompt)}
        {--no-readme : Do not update README.md with screenshots (skips prompt)}
        {--deps= : Comma-separated Composer package names to also scan (under vendor/) for publishes()/env() calls the plugin wraps}';

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

        // Re-running init (--force) must not silently wipe out manually curated
        // entries that auto-detection can't (re)produce on its own — e.g.
        // install.publish/env tags added for a wrapped dependency not passed to
        // --deps this time, seed.models for a plain non-Filament package, or
        // hand-added screenshots for routes with no matching Resource.
        $existingConfig = $configService->loadRaw($pluginPath) ?? [];

        // Check composer.json exists
        if (! $this->hasComposerJson($pluginPath)) {
            $this->error('No composer.json found. Are you in a Filament plugin directory?');

            return self::FAILURE;
        }

        $depPackages = array_filter(array_map('trim', explode(',', (string) $this->option('deps'))));

        // Analyze the plugin
        $analysis = null;

        $this->task('Analyzing plugin structure', function () use ($analyzer, $pluginPath, $depPackages, &$analysis) {
            $analysis = $analyzer->analyze($pluginPath, $depPackages);

            return true;
        });

        if (! $analysis instanceof PluginAnalysis) {
            $this->error('Failed to analyze plugin structure.');

            return self::FAILURE;
        }

        if ($analysis->pluginClass === null) {
            $this->warn('No Filament Plugin class detected — this doesn\'t look like a Filament plugin. '
                .'"install.plugins" will be left empty, the temp project installs plain "laravel/laravel" '
                .'instead of a Filakit base kit, and screenshots are auto-detected from routes/*.php where possible.');
            $this->newLine();
        }

        $interactive = $this->input->isInteractive();

        // Build screenshot options from detected resources
        $screenshotOptions = $this->buildScreenshotOptions($analysis);

        if ($interactive) {
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
        } else {
            $pluginName = $this->option('name') ?: $this->guessPluginName($analysis);
            $package = $this->option('package') ?: $analysis->package;

            $screenshotsOption = $this->option('screenshots');
            $selectedScreenshots = match (true) {
                $screenshotsOption === null => array_keys($screenshotOptions),
                strtolower($screenshotsOption) === 'all' => array_keys($screenshotOptions),
                $screenshotsOption === '' => [],
                default => array_map('trim', explode(',', $screenshotsOption)),
            };

            $updateReadme = ! $this->option('no-readme');
        }

        // A plain non-Filament package has no use for a Filakit base kit (it exists to
        // scaffold a Filament panel) — spin up an ordinary Laravel app instead, which is
        // all a package's own public routes/views need to run against.
        $filakitKit = $analysis->pluginClass === null ? 'laravel/laravel' : match ($analysis->filamentVersion) {
            FilamentVersion::V3 => 'filakitphp/basev3',
            FilamentVersion::V4 => 'filakitphp/basev4',
            FilamentVersion::V5 => 'filakitphp/basev5',
            default => 'filakitphp/basev5',
        };

        $existingPublish = is_array($existingConfig['install']['publish'] ?? null) ? $existingConfig['install']['publish'] : [];
        $existingEnv = is_array($existingConfig['install']['env'] ?? null) ? $existingConfig['install']['env'] : [];
        $existingModels = is_array($existingConfig['seed']['models'] ?? null) ? $existingConfig['seed']['models'] : [];
        $existingScreenshots = is_array($existingConfig['screenshots'] ?? null) ? $existingConfig['screenshots'] : [];
        $existingUser = is_array($existingConfig['seed']['user'] ?? null) ? $existingConfig['seed']['user'] : null;

        $userConfig = $this->resolveUserConfig($existingUser, $analysis);

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
                'plugins' => $analysis->pluginClass !== null ? [
                    [
                        'class' => $analysis->pluginClass,
                        'panel' => 'admin',
                    ],
                ] : [],
                'publish' => array_values(array_unique([...$existingPublish, ...$analysis->publishTags])),
                'post_install_commands' => ['migrate'],
                'env' => [...$existingEnv, ...$analysis->envCandidates],
            ],
            'seed' => [
                'auto_detect' => true,
                ...($userConfig !== null ? ['user' => $userConfig] : []),
                'models' => $this->mergeByKey($existingModels, $this->buildModelSeedConfig($selectedScreenshots, $analysis), 'model'),
            ],
            'screenshots' => $this->mergeScreenshots($existingScreenshots, $this->buildScreenshotsConfig($selectedScreenshots, $analysis)),
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

        if (! empty($analysis->pages)) {
            $this->info('Detected '.count($analysis->pages).' custom page(s):');
            foreach ($analysis->pages as $page) {
                $this->line('  - '.$page->name.' (/admin/'.$page->slug.')');
            }
            $this->newLine();
        }

        $authRoutes = array_values(array_filter($analysis->routes, fn (RouteInfo $route) => $route->auth));
        $unresolvedParamRoutes = array_values(array_filter(
            $analysis->routes,
            fn (RouteInfo $route) => ! $route->auth && $this->routeHasUnresolvedParams($route),
        ));
        $screenshottableRoutes = array_values(array_filter(
            $analysis->routes,
            fn (RouteInfo $route) => ! $route->auth && ! $this->routeHasUnresolvedParams($route),
        ));

        if (! empty($screenshottableRoutes)) {
            $this->info('Detected '.count($screenshottableRoutes).' named route(s):');
            foreach ($screenshottableRoutes as $route) {
                $this->line('  - '.$route->name.($route->signed ? ' (signed)' : ''));
            }
            $this->newLine();
        }

        if (! empty($authRoutes)) {
            $this->warn('Skipped '.count($authRoutes)." auth-gated route(s) — can't be auto-screenshotted without knowing how to authenticate: "
                .implode(', ', array_map(fn ($route) => $route->name, $authRoutes)));
            $this->newLine();
        }

        if (! empty($unresolvedParamRoutes)) {
            $this->warn('Skipped '.count($unresolvedParamRoutes)." route(s) with a required param that isn't route-model-bound "
                .'(no safe default value to guess — add manually with the right routeParams): '
                .implode(', ', array_map(fn ($route) => $route->name, $unresolvedParamRoutes)));
            $this->newLine();
        }

        if (! empty($analysis->publishTags)) {
            $this->info('Detected publish tag(s) from --deps: '.implode(', ', $analysis->publishTags));
            $this->newLine();
        }

        if (! empty($analysis->envCandidates)) {
            $this->info('Detected env flag(s) from --deps (defaulting to disabled): '.implode(', ', array_keys($analysis->envCandidates)));
            $this->newLine();
        }

        $this->info('Run "screentest capture" to generate screenshots.');

        return self::SUCCESS;
    }

    /**
     * Login credentials only matter for logging into a Filament panel — a plain
     * package has nothing at /admin/login to authenticate against, so there's nothing
     * to configure by default. Still honor a manually added "user" block though (an
     * existing config that's already been curated for it, e.g. because the package
     * has its own non-Filament login screen).
     *
     * @param  array<string, string>|null  $existingUser
     * @return array<string, string>|null
     */
    private function resolveUserConfig(?array $existingUser, PluginAnalysis $analysis): ?array
    {
        return $existingUser ?? ($analysis->pluginClass !== null ? [
            'email' => 'admin@example.com',
            'password' => 'password',
            'name' => 'Admin User',
        ] : null);
    }

    /**
     * Guess a human-readable plugin name from the analysis.
     */
    private function guessPluginName(PluginAnalysis $analysis): string
    {
        // Try to extract name from the plugin class (e.g., "MyPlugin" -> "My Plugin")
        $className = $analysis->pluginClass !== null
            ? str_replace('Plugin', '', class_basename($analysis->pluginClass))
            : '';

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

        foreach ($analysis->pages as $page) {
            $options["page-{$page->slug}"] = "{$page->name} (custom page)";
        }

        foreach ($analysis->routes as $route) {
            if ($route->auth || $this->routeHasUnresolvedParams($route)) {
                continue;
            }

            $options["route-{$route->name}"] = $route->signed ? "{$route->name} (signed route)" : $route->name;
        }

        return $options;
    }

    /**
     * A required route param only gets a safe default value (1) when it's resolved to
     * an Eloquent model class — a seeded record with id 1 is a reasonable guess. A
     * param the controller type-hints as a scalar (e.g. a string slug) has no such
     * guessable default; defaulting it to 1 anyway would produce a URL nothing matches
     * (e.g. `newsletter.webview`'s `{route}` is a slug like "monthly-update", not a
     * numeric id) — so that route is treated the same as an auth-gated one: skipped,
     * left for manual configuration.
     */
    private function routeHasUnresolvedParams(RouteInfo $route): bool
    {
        return count($route->params) > count($route->paramModels);
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
                    'name' => "{$slug}-list",
                    'url' => "/admin/{$pluralSlug}",
                ];
            }

            if (in_array("{$name}-create", $selectedKeys, true)) {
                $screenshots[] = [
                    'name' => "{$slug}-create",
                    'url' => "/admin/{$pluralSlug}/create",
                ];
            }

            if (in_array("{$name}-edit", $selectedKeys, true)) {
                $screenshots[] = [
                    'name' => "{$slug}-edit",
                    'url' => "/admin/{$pluralSlug}/1/edit",
                ];
            }
        }

        foreach ($analysis->pages as $page) {
            if (in_array("page-{$page->slug}", $selectedKeys, true)) {
                $screenshots[] = [
                    'name' => $page->slug,
                    'url' => "/admin/{$page->slug}",
                ];
            }
        }

        foreach ($analysis->routes as $route) {
            if ($route->auth || $this->routeHasUnresolvedParams($route) || ! in_array("route-{$route->name}", $selectedKeys, true)) {
                continue;
            }

            $entry = [
                'name' => str_replace('.', '-', $route->name),
                'route' => $route->name,
            ];

            if ($route->params !== []) {
                // A record has to exist for route-model-binding to resolve — id 1 mirrors
                // the /1/edit convention already used for resource screenshots above.
                $entry['routeParams'] = array_fill_keys($route->params, 1);
            }

            if ($route->signed) {
                $entry['signed'] = true;
            }

            $screenshots[] = $entry;
        }

        return $screenshots;
    }

    /**
     * Merge two lists of associative arrays, keeping every entry from $existing as-is
     * (preserving manual edits like a custom count/attributes/url) and appending only
     * the $incoming entries whose $key value isn't already present.
     *
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeByKey(array $existing, array $incoming, string $key): array
    {
        $merged = $existing;
        $seen = array_column($existing, $key);

        foreach ($incoming as $item) {
            if (! in_array($item[$key], $seen, true)) {
                $merged[] = $item;
                $seen[] = $item[$key];
            }
        }

        return $merged;
    }

    /**
     * Same idea as mergeByKey(), but a screenshot has two identities that both mean
     * "already covered, don't add another": its `name`, and — for a route-based
     * entry — the `route` it targets. Without the second check, an auto-detected route
     * screenshot (name: "newsletter-confirm") would duplicate a manually named one
     * hitting the exact same route (name: "confirmed") just because their `name`s differ.
     *
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $incoming
     * @return array<int, array<string, mixed>>
     */
    private function mergeScreenshots(array $existing, array $incoming): array
    {
        $merged = $existing;
        $seenNames = array_column($existing, 'name');
        $seenRoutes = array_column($existing, 'route');

        foreach ($incoming as $item) {
            if (in_array($item['name'], $seenNames, true)) {
                continue;
            }

            if (isset($item['route']) && in_array($item['route'], $seenRoutes, true)) {
                continue;
            }

            $merged[] = $item;
            $seenNames[] = $item['name'];

            if (isset($item['route'])) {
                $seenRoutes[] = $item['route'];
            }
        }

        return $merged;
    }

    /**
     * Build model seed configuration from detected resources, plus one record (count: 1,
     * just enough for route-model-binding to resolve) per Eloquent model a selected
     * route's controller action type-hints — skipped if a resource already seeds that
     * model with its own (larger) count.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildModelSeedConfig(array $selectedKeys, PluginAnalysis $analysis): array
    {
        $models = [];
        $seen = [];

        foreach ($analysis->resources as $resource) {
            $models[] = [
                'model' => $resource->model,
                'count' => 10,
            ];
            $seen[$resource->model] = true;
        }

        foreach ($analysis->routes as $route) {
            if (! in_array("route-{$route->name}", $selectedKeys, true)) {
                continue;
            }

            foreach ($route->paramModels as $modelClass) {
                if (isset($seen[$modelClass])) {
                    continue;
                }

                $models[] = [
                    'model' => $modelClass,
                    'count' => 1,
                ];
                $seen[$modelClass] = true;
            }
        }

        return $models;
    }
}
