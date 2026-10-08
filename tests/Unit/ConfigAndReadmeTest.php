<?php

use App\DTOs\CaptureResult;
use App\DTOs\ScreentestConfig;
use App\DTOs\SeedConfig;
use App\Services\ReadmeService;

it('reads the documented snake_case seed.auto_detect key', function () {
    expect(SeedConfig::fromArray(['auto_detect' => false])->autoDetect)->toBeFalse()
        ->and(SeedConfig::fromArray(['autoDetect' => false])->autoDetect)->toBeFalse()
        ->and(SeedConfig::fromArray([])->autoDetect)->toBeTrue();
});

it('fills a README that has only a single section marker', function () {
    $dir = sys_get_temp_dir().'/screentest-readme-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/README.md', "# Pkg\n\n<!-- SCREENSHOTS -->\n\n## Usage\n");

    $config = ScreentestConfig::fromArray([
        'plugin' => ['name' => 'Pkg', 'package' => 'vendor/pkg'],
        'readme' => ['update' => true],
        'output' => ['themes' => ['light']],
    ]);

    app(ReadmeService::class)->update($config, $dir, [
        new CaptureResult(name: 'list', theme: 'light', path: 'screenshots/light/list.png', success: true),
    ]);

    $readme = file_get_contents($dir.'/README.md');

    expect(substr_count($readme, '<!-- SCREENSHOTS -->'))->toBe(2)
        ->and($readme)->toContain('screenshots/light/list.png')
        ->and($readme)->toContain('## Usage');
});
