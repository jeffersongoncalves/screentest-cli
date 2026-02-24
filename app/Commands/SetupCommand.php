<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\LoadsConfig;
use App\Services\ProjectService;
use LaravelZero\Framework\Commands\Command;

class SetupCommand extends Command
{
    use LoadsConfig;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'setup
        {--path= : Plugin directory path}
        {--keep : Keep temp project on failure}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Setup temporary Filament project for screenshots';

    /**
     * Execute the console command.
     */
    public function handle(ProjectService $project): int
    {
        $config = $this->loadConfig($this->option('path'));
        $pluginPath = $this->resolvePluginPath($this->option('path'));
        $projectPath = null;

        try {
            $this->task('Creating temporary project', function () use ($project, $config, &$projectPath) {
                $projectPath = $project->create($config);
            });

            $this->task('Installing plugin', function () use ($project, $config, $pluginPath, &$projectPath) {
                $project->installPlugin($config, $projectPath, $pluginPath);
            });

            $this->task('Registering plugins', function () use ($project, $config, &$projectPath) {
                $project->registerPlugins($config, $projectPath);
            });

            $this->task('Publishing assets', function () use ($project, $config, &$projectPath) {
                $project->publishAssets($config, $projectPath);
            });

            $this->task('Running post-install commands', function () use ($project, $config, &$projectPath) {
                $project->runPostInstallCommands($config, $projectPath);
            });

            $this->task('Building frontend assets', function () use ($project, &$projectPath) {
                $project->buildAssets($projectPath);
            });
        } catch (\Throwable $e) {
            if (! $this->option('keep') && $projectPath) {
                $project->cleanup($projectPath);
            }

            throw $e;
        }

        $this->newLine();
        $this->info("Temporary project created at: {$projectPath}");

        return self::SUCCESS;
    }
}
