<?php

use App\Commands\InitCommand;
use App\DTOs\PluginAnalysis;
use App\DTOs\ResourceInfo;
use App\DTOs\RouteInfo;

it('kebab-cases a PascalCase multi-word model name for screenshot names, not a plain lowercase', function () {
    $command = new InitCommand;

    $analysis = new PluginAnalysis(
        pluginClass: 'Acme\\Newsletter\\NewsletterPlugin',
        package: 'acme/newsletter',
        filamentVersion: null,
        resources: [
            new ResourceInfo(
                class: 'Acme\\Newsletter\\EmailGroupResource',
                model: 'Acme\\Newsletter\\Models\\EmailGroup',
                modelShortName: 'EmailGroup',
            ),
        ],
    );

    $build = Closure::bind(
        fn () => $this->buildScreenshotsConfig(['EmailGroup-list', 'EmailGroup-create', 'EmailGroup-edit'], $analysis),
        $command,
        InitCommand::class,
    );

    expect($build())->toBe([
        ['name' => 'email-group-list', 'url' => '/admin/email-groups'],
        ['name' => 'email-group-create', 'url' => '/admin/email-groups/create'],
        ['name' => 'email-group-edit', 'url' => '/admin/email-groups/1/edit'],
    ]);
});

it('falls back to the package name for the plugin name guess when no Filament Plugin class was detected', function () {
    $command = new InitCommand;

    $analysis = new PluginAnalysis(
        pluginClass: null,
        package: 'acme/laravel-newsletter',
        filamentVersion: null,
    );

    $guess = Closure::bind(fn () => $this->guessPluginName($analysis), $command, InitCommand::class)();

    expect($guess)->toBe('Laravel Newsletter');
});

it('preserves existing entries on re-init (mergeByKey) instead of dropping manual curation auto-detection cannot reproduce', function () {
    $command = new InitCommand;

    $merge = Closure::bind(
        fn (array $existing, array $incoming, string $key) => $this->mergeByKey($existing, $incoming, $key),
        $command,
        InitCommand::class,
    );

    // Mirrors a plain non-Filament package: auto-detection finds nothing new
    // (no Resources), so a re-run with --force must not wipe the manually
    // added screenshot for a route with no matching Resource.
    $existingScreenshots = [
        ['name' => 'subscribe', 'url' => '/newsletter/subscribe'],
        ['name' => 'webview', 'url' => '/newsletter/monthly-update'],
    ];

    expect($merge($existingScreenshots, [], 'name'))->toBe($existingScreenshots);

    // A newly detected entry (e.g. a Resource added since the last init) gets
    // appended; an entry sharing a key with an existing one is not duplicated
    // and the existing (possibly hand-edited) version wins.
    $incomingScreenshots = [
        ['name' => 'webview', 'url' => '/newsletter/monthly-update-CHANGED'],
        ['name' => 'thing-list', 'url' => '/admin/things'],
    ];

    expect($merge($existingScreenshots, $incomingScreenshots, 'name'))->toBe([
        ['name' => 'subscribe', 'url' => '/newsletter/subscribe'],
        ['name' => 'webview', 'url' => '/newsletter/monthly-update'],
        ['name' => 'thing-list', 'url' => '/admin/things'],
    ]);
});

it('turns a selected signed route into a screenshot entry with routeParams/signed, and seeds its route-model-bound param', function () {
    $command = new InitCommand;

    $analysis = new PluginAnalysis(
        pluginClass: null,
        package: 'acme/newsletter',
        filamentVersion: null,
        routes: [
            new RouteInfo(name: 'newsletter.subscribe.form', uri: 'newsletter/subscribe'),
            new RouteInfo(
                name: 'newsletter.confirm',
                uri: 'newsletter/confirm/{emailGroupMember}',
                signed: true,
                params: ['emailGroupMember'],
                paramModels: ['emailGroupMember' => 'Acme\\Newsletter\\Models\\EmailGroupMember'],
            ),
            new RouteInfo(name: 'admin.dashboard', uri: 'admin/dashboard', auth: true),
        ],
    );

    $selectedKeys = ['route-newsletter.subscribe.form', 'route-newsletter.confirm'];

    $screenshots = Closure::bind(
        fn () => $this->buildScreenshotsConfig($selectedKeys, $analysis),
        $command,
        InitCommand::class,
    )();

    expect($screenshots)->toBe([
        ['name' => 'newsletter-subscribe-form', 'route' => 'newsletter.subscribe.form'],
        ['name' => 'newsletter-confirm', 'route' => 'newsletter.confirm', 'routeParams' => ['emailGroupMember' => 1], 'signed' => true],
    ]);

    $options = Closure::bind(fn () => $this->buildScreenshotOptions($analysis), $command, InitCommand::class)();

    // The auth-gated route never becomes a selectable option at all — there's
    // no way to auto-authenticate for it.
    expect($options)->toHaveKeys(['route-newsletter.subscribe.form', 'route-newsletter.confirm'])
        ->not->toHaveKey('route-admin.dashboard');

    $models = Closure::bind(
        fn () => $this->buildModelSeedConfig($selectedKeys, $analysis),
        $command,
        InitCommand::class,
    )();

    expect($models)->toBe([
        ['model' => 'Acme\\Newsletter\\Models\\EmailGroupMember', 'count' => 1],
    ]);
});

it('skips a route with a required param that is not route-model-bound instead of guessing a wrong default', function () {
    $command = new InitCommand;

    // Mirrors newsletter.webview: `{route}` is a string slug the controller
    // type-hints as `string`, not an Eloquent model — no safe value to guess.
    $analysis = new PluginAnalysis(
        pluginClass: null,
        package: 'acme/newsletter',
        filamentVersion: null,
        routes: [
            new RouteInfo(name: 'newsletter.webview', uri: 'newsletter/{route}', params: ['route'], paramModels: []),
        ],
    );

    $options = Closure::bind(fn () => $this->buildScreenshotOptions($analysis), $command, InitCommand::class)();

    expect($options)->toBe([]);

    // Even if explicitly forced via --screenshots, buildScreenshotsConfig itself
    // refuses to fabricate a routeParams guess for it.
    $screenshots = Closure::bind(
        fn () => $this->buildScreenshotsConfig(['route-newsletter.webview'], $analysis),
        $command,
        InitCommand::class,
    )();

    expect($screenshots)->toBe([]);
});

it('dedupes a re-init\'s auto-detected route screenshot against an existing manually named entry for the same route', function () {
    $command = new InitCommand;

    $merge = Closure::bind(
        fn (array $existing, array $incoming) => $this->mergeScreenshots($existing, $incoming),
        $command,
        InitCommand::class,
    );

    // The user already hand-added "confirmed" targeting newsletter.confirm before
    // route auto-detection existed; a re-init's freshly auto-named
    // "newsletter-confirm" for that same route must not be added alongside it.
    $existing = [
        ['name' => 'confirmed', 'route' => 'newsletter.confirm', 'routeParams' => ['emailGroupMember' => 1], 'signed' => true],
    ];

    $incoming = [
        ['name' => 'newsletter-confirm', 'route' => 'newsletter.confirm', 'routeParams' => ['emailGroupMember' => 1], 'signed' => true],
        ['name' => 'newsletter-subscribe-form', 'route' => 'newsletter.subscribe.form'],
    ];

    expect($merge($existing, $incoming))->toBe([
        ['name' => 'confirmed', 'route' => 'newsletter.confirm', 'routeParams' => ['emailGroupMember' => 1], 'signed' => true],
        ['name' => 'newsletter-subscribe-form', 'route' => 'newsletter.subscribe.form'],
    ]);
});
