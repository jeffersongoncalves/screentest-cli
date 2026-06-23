<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\CaptureService;
use App\Services\ConfigService;
use App\Services\PluginAnalyzerService;
use App\Services\ProcessService;
use App\Services\ProjectService;
use App\Services\ReadmeService;
use App\Services\SeedService;
use Illuminate\Support\ServiceProvider;
use JeffersonGoncalves\LaravelZero\SelfUpdate\PharUpdater;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProcessService::class);
        $this->app->singleton(ConfigService::class);
        $this->app->singleton(PluginAnalyzerService::class);
        $this->app->singleton(ReadmeService::class);

        $this->app->singleton(ProjectService::class, function ($app) {
            return new ProjectService($app->make(ProcessService::class));
        });

        $this->app->singleton(SeedService::class, function ($app) {
            return new SeedService(
                $app->make(ProcessService::class),
                $app->make(PluginAnalyzerService::class),
            );
        });

        $this->app->singleton(CaptureService::class, function ($app) {
            return new CaptureService($app->make(ProcessService::class));
        });

        $this->app->singleton(PharUpdater::class, fn () => new PharUpdater(
            githubRepo: 'jeffersongoncalves/screentest-cli',
            assetName: 'screentest.phar',
            tempPrefix: 'screentest_',
            currentVersion: (string) config('app.version', 'unreleased'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
