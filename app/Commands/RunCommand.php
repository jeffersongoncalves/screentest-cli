<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\LoadsConfig;
use App\Services\CaptureService;
use App\Services\ProjectService;
use App\Services\ReadmeService;
use App\Services\SeedService;
use LaravelZero\Framework\Commands\Command;

class RunCommand extends Command
{
    use LoadsConfig;

    protected $signature = 'run
        {--path= : Plugin directory path}
        {--keep : Keep temporary project after completion}
        {--skip-seed : Skip seeding}
        {--skip-capture : Skip screenshot capture}
        {--skip-readme : Skip README update}';

    protected $description = 'Run the complete screenshot generation pipeline';

    public function handle(
        ProjectService $project,
        SeedService $seed,
        CaptureService $capture,
        ReadmeService $readme,
    ): int {
        $pluginPath = $this->resolvePluginPath($this->option('path'));
        $config = $this->loadConfig($this->option('path'));
        $projectPath = null;
        $serverProcess = null;

        $this->info("Screentest - {$config->plugin->name}");
        $this->newLine();

        try {
            // Step 1: Setup
            $this->task('Creating temporary project', function () use ($project, $config, &$projectPath) {
                $projectPath = $project->create($config);
            });

            $this->task('Installing plugin', function () use ($project, $config, $projectPath, $pluginPath) {
                $project->installPlugin($config, $projectPath, $pluginPath);
            });

            $this->task('Registering plugins in panels', function () use ($project, $config, $projectPath) {
                $project->registerPlugins($config, $projectPath);
            });

            $this->task('Publishing assets', function () use ($project, $config, $projectPath) {
                $project->publishAssets($config, $projectPath);
            });

            $this->task('Running post-install commands', function () use ($project, $config, $projectPath) {
                $project->runPostInstallCommands($config, $projectPath);
            });

            $this->task('Building frontend assets', function () use ($project, $projectPath) {
                $project->buildAssets($projectPath);
            });

            // Step 2: Seed
            if (! $this->option('skip-seed')) {
                $this->task('Generating and running seeds', function () use ($seed, $config, $projectPath, $pluginPath) {
                    $seed->generateAndRun($config, $projectPath, $pluginPath);
                });
            }

            // Step 3: Server (conditional)
            if ($project->needsServer($projectPath)) {
                $this->task('Starting development server', function () use ($project, $projectPath, &$serverProcess) {
                    $serverProcess = $project->startServer($projectPath);
                });
            } else {
                $this->task('Waiting for Herd to serve project', function () use ($project, $projectPath) {
                    $project->waitForHerd($projectPath);
                });
            }

            // Step 4: Capture
            $baseUrl = $project->getBaseUrl($projectPath);

            if (! $this->option('skip-capture')) {
                $results = [];

                $this->task('Capturing screenshots', function () use ($capture, $config, $projectPath, $pluginPath, $baseUrl, &$results) {
                    $results = $capture->capture($config, $projectPath, $pluginPath, $baseUrl);
                });

                $this->newLine();

                $successful = array_filter($results, fn ($r) => $r->success);
                $failed = array_filter($results, fn ($r) => ! $r->success);

                $this->info(count($successful).' screenshots captured successfully.');

                if (! empty($failed)) {
                    $this->warn(count($failed).' screenshots failed:');
                    foreach ($failed as $result) {
                        $this->line("  - {$result->name} ({$result->theme}): {$result->error}");
                    }
                }

                // Step 5: README
                if (! $this->option('skip-readme') && $config->readme->update) {
                    $this->task('Updating README.md', function () use ($readme, $config, $pluginPath, $results) {
                        $readme->update($config, $pluginPath, $results);
                    });
                }
            }

            $this->newLine();
            $this->info('Done! Screenshots saved to: '.$pluginPath.'/'.$config->output->directory);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Pipeline failed: '.$e->getMessage());

            if ($this->getOutput()->isVerbose()) {
                $this->line($e->getTraceAsString());
            }

            return self::FAILURE;
        } finally {
            // Stop server
            if ($serverProcess) {
                $project->stopServer($serverProcess);
            }

            // Cleanup
            if ($projectPath && ! $this->option('keep')) {
                $this->task('Cleaning up temporary project', function () use ($project, $projectPath) {
                    $project->cleanup($projectPath);
                });
            } elseif ($projectPath && $this->option('keep')) {
                $this->newLine();
                $this->comment("Temporary project kept at: {$projectPath}");
            }
        }
    }
}
