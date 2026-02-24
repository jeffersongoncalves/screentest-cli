<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\LoadsConfig;
use App\DTOs\CaptureResult;
use App\Enums\Theme;
use App\Services\ReadmeService;
use LaravelZero\Framework\Commands\Command;

class ReadmeCommand extends Command
{
    use LoadsConfig;

    protected $signature = 'readme
        {--path= : Plugin directory path}';

    protected $description = 'Update README.md with screenshot references';

    public function handle(ReadmeService $readme): int
    {
        $pluginPath = $this->resolvePluginPath($this->option('path'));
        $config = $this->loadConfig($this->option('path'));

        if (! $config->readme->update) {
            $this->info('README update is disabled in config.');

            return self::SUCCESS;
        }

        $readmePath = $pluginPath.'/README.md';

        if (! file_exists($readmePath)) {
            $this->error('README.md not found at: '.$readmePath);

            return self::FAILURE;
        }

        // Build results from existing screenshots on disk
        $results = $this->discoverExistingScreenshots($config, $pluginPath);

        if (empty($results)) {
            $this->warn('No screenshots found. Run "screentest capture" first.');

            return self::FAILURE;
        }

        $this->task('Updating README.md', function () use ($readme, $config, $pluginPath, $results) {
            $readme->update($config, $pluginPath, $results);
        });

        $this->newLine();
        $this->info('README.md updated with '.count($results).' screenshot references.');

        return self::SUCCESS;
    }

    protected function discoverExistingScreenshots($config, string $pluginPath): array
    {
        $results = [];
        $dir = $pluginPath.'/'.$config->output->directory;
        $format = $config->output->format->value;

        foreach ($config->output->themes as $theme) {
            $themeDir = $dir.'/'.$theme->value;

            if (! is_dir($themeDir)) {
                continue;
            }

            foreach ($config->screenshots as $screenshot) {
                $filePath = $themeDir.'/'.$screenshot->name.'.'.$format;

                if (file_exists($filePath)) {
                    $results[] = new CaptureResult(
                        name: $screenshot->name,
                        theme: $theme->value,
                        path: $config->output->directory.'/'.$theme->value.'/'.$screenshot->name.'.'.$format,
                        success: true,
                    );
                }
            }
        }

        return $results;
    }
}
