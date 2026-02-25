<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PHP Binary Path
    |--------------------------------------------------------------------------
    */

    'php_binary' => env('SCREENTEST_PHP_BINARY', 'php'),

    /*
    |--------------------------------------------------------------------------
    | Composer Binary Path
    |--------------------------------------------------------------------------
    */

    'composer_binary' => env('SCREENTEST_COMPOSER_BINARY', 'composer'),

    /*
    |--------------------------------------------------------------------------
    | Node Binary Path
    |--------------------------------------------------------------------------
    */

    'node_binary' => env('SCREENTEST_NODE_BINARY', 'node'),

    /*
    |--------------------------------------------------------------------------
    | PNPM Binary Path
    |--------------------------------------------------------------------------
    */

    'pnpm_binary' => env('SCREENTEST_PNPM_BINARY', 'pnpm'),

    /*
    |--------------------------------------------------------------------------
    | Laravel Herd Integration
    |--------------------------------------------------------------------------
    |
    | When enabled, projects are created inside the Herd directory and served
    | automatically via http://{dirname}.{tld} — no server process needed.
    |
    | 'auto' = detect Herd availability, true = force Herd, false = use php -S
    |
    */

    'herd' => [
        'enabled' => env('SCREENTEST_HERD_ENABLED', 'auto'),
        'directory' => env('SCREENTEST_HERD_DIR', null),
        'tld' => env('SCREENTEST_HERD_TLD', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development Server (fallback when Herd is not available)
    |--------------------------------------------------------------------------
    */

    'server' => [
        'host' => env('SCREENTEST_SERVER_HOST', '127.0.0.1'),
        'port' => (int) env('SCREENTEST_SERVER_PORT', 8787),
        'startup_timeout' => (int) env('SCREENTEST_SERVER_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Project Directory
    |--------------------------------------------------------------------------
    */

    'temp_directory' => env('SCREENTEST_TEMP_DIR', str_replace('\\', '/', sys_get_temp_dir()).'/screentest-temp'),

    /*
    |--------------------------------------------------------------------------
    | Capture Timeouts
    |--------------------------------------------------------------------------
    */

    'capture' => [
        'navigation_timeout' => (int) env('SCREENTEST_NAV_TIMEOUT', 30000),
        'screenshot_timeout' => (int) env('SCREENTEST_SCREENSHOT_TIMEOUT', 10000),
    ],

];
