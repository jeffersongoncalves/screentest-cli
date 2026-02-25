<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\LoadsConfig;
use App\DTOs\CaptureResult;
use App\Services\CaptureService;
use App\Services\ProjectService;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class CaptureCommand extends Command
{
    use LoadsConfig;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'capture
        {--path= : Plugin directory path}
        {--project= : Temp project path}
        {--theme= : Only capture specific theme}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Capture screenshots using Puppeteer';

    /**
     * Execute the console command.
     */
    public function handle(CaptureService $captureService, ProjectService $projectService): int
    {
        $config = $this->loadConfig($this->option('path'));
        $pluginPath = $this->resolvePluginPath($this->option('path'));
        $projectPath = $this->option('project') ?? config('screentest.temp_directory');

        if (! is_dir($projectPath)) {
            $this->error("Project path does not exist: {$projectPath}");

            return self::FAILURE;
        }

        // Filter themes if --theme option is provided
        if ($themeFilter = $this->option('theme')) {
            $filteredThemes = array_filter(
                $config->output->themes,
                fn ($theme) => $theme->value === $themeFilter,
            );

            if (empty($filteredThemes)) {
                $this->error("Invalid theme: {$themeFilter}. Available themes: ".implode(', ', array_map(fn ($t) => $t->value, $config->output->themes)));

                return self::FAILURE;
            }

            $config = new \App\DTOs\ScreentestConfig(
                plugin: $config->plugin,
                filakit: $config->filakit,
                install: $config->install,
                seed: $config->seed,
                screenshots: $config->screenshots,
                output: new \App\DTOs\OutputConfig(
                    directory: $config->output->directory,
                    themes: array_values($filteredThemes),
                    format: $config->output->format,
                ),
                readme: $config->readme,
            );
        }

        // Verify server is running
        $baseUrl = $projectService->getBaseUrl($projectPath);

        $this->info("Verifying server at {$baseUrl}...");

        try {
            $response = Http::timeout(5)->get($baseUrl);

            if (! $response->successful() && ! $response->redirect()) {
                $this->error("Server is not responding properly at {$baseUrl}");

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Cannot connect to server at {$baseUrl}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Server is running. Starting capture...');
        $this->newLine();

        $screenshotCount = count($config->screenshots);
        $themeCount = count($config->output->themes);
        $totalCaptures = $screenshotCount * $themeCount;

        $this->info("Capturing {$totalCaptures} screenshots ({$screenshotCount} pages x {$themeCount} themes)...");
        $this->newLine();

        try {
            $results = $captureService->capture($config, $projectPath, $pluginPath, $baseUrl);
        } catch (\Exception $e) {
            $this->error("Capture failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // Output summary table
        $successCount = count(array_filter($results, fn (CaptureResult $r) => $r->success));
        $failCount = count($results) - $successCount;

        $this->table(
            ['Name', 'Theme', 'Status', 'Path'],
            array_map(fn (CaptureResult $r) => [
                $r->name,
                $r->theme,
                $r->success ? '<fg=green>OK</>' : '<fg=red>FAIL</>',
                $r->success ? $r->path : ($r->error ?? 'Unknown error'),
            ], $results),
        );

        $this->newLine();
        $this->info("Done! {$successCount} captured, {$failCount} failed.");

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
