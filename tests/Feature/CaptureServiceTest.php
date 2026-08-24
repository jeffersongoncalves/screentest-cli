<?php

use App\DTOs\CaptureResult;
use App\DTOs\ScreentestConfig;
use App\Services\CaptureService;
use App\Services\ProcessService;
use Illuminate\Support\Facades\Process;

it('uses base_path(stubs/docker) directly when not running from a phar', function () {
    $service = new CaptureService(new ProcessService);

    // Pest itself never runs from inside a compiled .phar, so Phar::running()
    // is empty here — exercises the source-checkout branch of dockerBuildContextPath().
    $context = Closure::bind(fn () => $this->dockerBuildContextPath(), $service, CaptureService::class)();

    expect($context)->toBe(base_path('stubs/docker'));
});

it('signs a route absolute (Laravel\'s default) instead of relative, since ValidateSignature validates against the absolute URL', function () {
    // Regression for #14: URL::signedRoute(..., null, false) signs the relative
    // path, but the `signed` middleware's hasValidSignature() defaults to
    // validating the ABSOLUTE URL — that mismatch made every signed screenshot
    // fail with a 403 "Invalid signature", the exact case this feature exists for.
    $stub = file_get_contents(base_path('stubs/resolve_urls.php.stub'));

    expect($stub)->not->toContain('null, false')
        ->and($stub)->toContain("URL::signedRoute(\$entry['route'], \$entry['params'])")
        ->and($stub)->toContain("config('app.url')");

    $service = new CaptureService(new ProcessService);
    $embedded = Closure::bind(fn () => $this->buildUrlResolverScript('dummy'), $service, CaptureService::class)();

    expect($embedded)->not->toContain('null, false')
        ->and($embedded)->toContain('URL::signedRoute(')
        ->and($embedded)->toContain("config('app.url')");
});

it('resolves a signed-route screenshot to the URL the temp project\'s own artisan command produced', function () {
    Process::fake([
        '*screentest:resolve-urls*' => Process::result(output: json_encode([
            'confirm' => ['url' => '/newsletter/confirm/1?signature=abc123'],
        ])),
    ]);

    $service = new CaptureService(new ProcessService);

    $config = ScreentestConfig::fromArray([
        'plugin' => ['name' => 'Newsletter', 'package' => 'acme/newsletter'],
        'screenshots' => [
            [
                'name' => 'confirm',
                'route' => 'newsletter.confirm',
                'routeParams' => ['emailGroupMember' => 1],
                'signed' => true,
            ],
        ],
    ]);

    $resolve = Closure::bind(
        fn () => $this->resolveRouteUrls($config, sys_get_temp_dir().'/screentest-fixture-resolve-'.uniqid()),
        $service,
        CaptureService::class,
    );

    [$overrides, $failures] = $resolve();

    expect($overrides)->toBe(['confirm' => '/newsletter/confirm/1?signature=abc123'])
        ->and($failures)->toBe([]);
});

it('reports a failed CaptureResult per theme when a route fails to resolve, instead of navigating to an empty URL', function () {
    Process::fake([
        '*screentest:resolve-urls*' => Process::result(output: json_encode([
            'confirm' => ['error' => 'Missing required parameter for [Route: newsletter.confirm] [URI: newsletter/confirm/{emailGroupMember}] [Missing parameter: emailGroupMember].'],
        ])),
    ]);

    $service = new CaptureService(new ProcessService);

    $config = ScreentestConfig::fromArray([
        'plugin' => ['name' => 'Newsletter', 'package' => 'acme/newsletter'],
        'screenshots' => [
            ['name' => 'confirm', 'route' => 'newsletter.confirm', 'signed' => true],
        ],
    ]);

    $resolve = Closure::bind(
        fn () => $this->resolveRouteUrls($config, sys_get_temp_dir().'/screentest-fixture-resolve-'.uniqid()),
        $service,
        CaptureService::class,
    );

    [$overrides, $failures] = $resolve();

    expect($overrides)->toBe([])
        ->and($failures)->toHaveCount(2) // one per default theme (light, dark)
        ->and($failures[0])->toBeInstanceOf(CaptureResult::class)
        ->and($failures[0]->success)->toBeFalse()
        ->and($failures[0]->error)->toContain("could not resolve route 'newsletter.confirm'")
        ->and($failures[0]->error)->toContain('Missing parameter');
});
