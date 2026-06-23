<?php

declare(strict_types=1);

namespace App\Commands;

use JeffersonGoncalves\LaravelZero\SelfUpdate\PharUpdater;
use JeffersonGoncalves\LaravelZero\SelfUpdate\SelfUpdateCommand as BaseSelfUpdateCommand;

class SelfUpdateCommand extends BaseSelfUpdateCommand
{
    protected $description = 'Update the screentest CLI to the latest version';

    protected function githubRepo(): string
    {
        return 'jeffersongoncalves/screentest-cli';
    }

    protected function assetName(): string
    {
        return 'screentest.phar';
    }

    protected function tempPrefix(): string
    {
        return 'screentest_';
    }

    protected function currentVersion(): string
    {
        return (string) config('app.version', 'unreleased');
    }

    protected function makeUpdater(): PharUpdater
    {
        return $this->getLaravel()->make(PharUpdater::class);
    }
}
