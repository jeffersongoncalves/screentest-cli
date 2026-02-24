<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\LoadsConfig;
use App\Services\ProjectService;
use LaravelZero\Framework\Commands\Command;

class CleanupCommand extends Command
{
    use LoadsConfig;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'cleanup
        {--path= : Plugin directory path}
        {--project= : Temp project path to clean}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Remove temporary project';

    /**
     * Execute the console command.
     */
    public function handle(ProjectService $project): int
    {
        $projectPath = $this->option('project');

        if (! $projectPath) {
            $projectPath = config('screentest.temp_directory');
        }

        if (! is_dir($projectPath)) {
            $this->warn("Directory does not exist: {$projectPath}");

            return self::SUCCESS;
        }

        $project->cleanup($projectPath);

        $this->info("Temporary project removed: {$projectPath}");

        return self::SUCCESS;
    }
}
