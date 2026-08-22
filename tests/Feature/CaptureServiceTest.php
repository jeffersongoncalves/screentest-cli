<?php

use App\Services\CaptureService;
use App\Services\ProcessService;

it('uses base_path(stubs/docker) directly when not running from a phar', function () {
    $service = new CaptureService(new ProcessService);

    // Pest itself never runs from inside a compiled .phar, so Phar::running()
    // is empty here — exercises the source-checkout branch of dockerBuildContextPath().
    $context = Closure::bind(fn () => $this->dockerBuildContextPath(), $service, CaptureService::class)();

    expect($context)->toBe(base_path('stubs/docker'));
});
