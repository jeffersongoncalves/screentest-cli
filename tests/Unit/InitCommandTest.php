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
