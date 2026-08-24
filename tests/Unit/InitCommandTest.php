<?php

use App\Commands\InitCommand;
use App\DTOs\PluginAnalysis;
use App\DTOs\ResourceInfo;

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
