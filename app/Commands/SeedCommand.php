<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\LoadsConfig;
use App\Services\SeedService;
use LaravelZero\Framework\Commands\Command;

class SeedCommand extends Command
{
    use LoadsConfig;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'seed {--path= : Plugin directory path} {--project= : Temp project path}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Generate and run seeds for the temporary project';

    /**
     * Execute the console command.
     */
    public function handle(SeedService $seedService): int
    {
        $config = $this->loadConfig($this->option('path'));

        $pluginPath = $this->resolvePluginPath($this->option('path'));

        $projectPath = $this->option('project') ?? config('screentest.temp_directory');

        if (! $projectPath || ! is_dir($projectPath)) {
            $this->error('Project path does not exist: '.($projectPath ?? 'not specified'));

            return self::FAILURE;
        }

        $this->task('Generating and running seeds', function () use ($seedService, $config, $projectPath, $pluginPath) {
            $seedService->generateAndRun($config, $projectPath, $pluginPath);
        });

        $seedCount = 1 + count($config->seed->models);
        if ($config->seed->autoDetect) {
            $this->info('Seeds generated and executed with auto-detection enabled.');
        } else {
            $this->info("Seeds generated and executed for {$seedCount} model(s).");
        }

        return self::SUCCESS;
    }
}
