<?php

use App\DTOs\FieldInfo;
use App\Enums\FilamentVersion;
use App\Services\PluginAnalyzerService;

function makeFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-'.uniqid();

    mkdir($root.'/src/PostResource/Schemas', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/blog',
        'autoload' => [
            'psr-4' => [
                'Acme\\Blog\\' => 'src/',
            ],
        ],
        'require' => [
            'filament/filament' => '^3.0',
        ],
    ]));

    // Mirrors the common convention (used across the jeffersongoncalves/filament-*
    // plugins) where a Resource's form() delegates to a dedicated Schemas\*Form
    // class instead of inlining field components directly in the Resource file.
    file_put_contents($root.'/src/PostResource.php', <<<'PHP'
        <?php

        namespace Acme\Blog;

        use Filament\Resources\Resource;
        use Acme\Blog\PostResource\Schemas\PostForm;

        class PostResource extends Resource
        {
            protected static ?string $model = Post::class;

            public static function form(Schema $schema): Schema
            {
                return PostForm::configure($schema);
            }
        }
        PHP);

    file_put_contents($root.'/src/PostResource/Schemas/PostForm.php', <<<'PHP'
        <?php

        namespace Acme\Blog\PostResource\Schemas;

        use Filament\Forms\Components\TextInput;
        use Filament\Forms\Components\Select;

        class PostForm
        {
            public static function configure(Schema $schema): Schema
            {
                return $schema->schema([
                    TextInput::make('title')->required(),
                    Select::make('category_id')->options(['news' => 'News', 'blog' => 'Blog']),
                ]);
            }
        }
        PHP);

    return $root;
}

it('follows a delegated Form::configure() call to find fields defined in a separate Schemas class', function () {
    $pluginPath = makeFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath);

    expect($analysis->resources)->toHaveCount(1);

    $fieldNames = array_map(fn ($field) => $field->name, $analysis->resources[0]->fields);

    expect($fieldNames)->toContain('title')
        ->toContain('category_id');
});

function makeV3StyleFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-v3-'.uniqid();

    mkdir($root.'/src', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/legacy-blog',
        'autoload' => [
            'psr-4' => [
                'Acme\\LegacyBlog\\' => 'src/',
            ],
        ],
        'require' => [
            'filament/filament' => '^3.3',
        ],
    ]));

    // Classic Filament v3 style: fields inlined directly in the Resource's
    // form() method using the old Form $form / Form return type, no
    // delegation to a separate Schemas class.
    file_put_contents($root.'/src/PostResource.php', <<<'PHP'
        <?php

        namespace Acme\LegacyBlog;

        use Filament\Forms\Form;
        use Filament\Forms\Components\TextInput;
        use Filament\Forms\Components\Toggle;
        use Filament\Resources\Resource;

        class PostResource extends Resource
        {
            protected static ?string $model = Post::class;

            public static function form(Form $form): Form
            {
                return $form->schema([
                    TextInput::make('title')->required(),
                    Toggle::make('is_published'),
                ]);
            }
        }
        PHP);

    return $root;
}

it('still detects inline fields and the Filament v3 version constraint (no delegation involved)', function () {
    $pluginPath = makeV3StyleFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath);

    expect($analysis->filamentVersion)->toBe(FilamentVersion::V3)
        ->and($analysis->resources)->toHaveCount(1);

    $fieldNames = array_map(fn ($field) => $field->name, $analysis->resources[0]->fields);

    expect($fieldNames)->toContain('title')
        ->toContain('is_published');
});

function makeDependencyWrapperFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-deps-'.uniqid();

    mkdir($root.'/src', recursive: true);
    mkdir($root.'/vendor/acme/laravel-short-url/src', recursive: true);
    mkdir($root.'/vendor/acme/laravel-short-url/config', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/filament-short-url',
        'autoload' => [
            'psr-4' => [
                'Acme\\FilamentShortUrl\\' => 'src/',
            ],
        ],
        'require' => [
            'filament/filament' => '^3.0',
            'acme/laravel-short-url' => '^1.0',
        ],
    ]));

    // Mirrors jeffersongoncalves/filament-short-url: the Filament plugin has no
    // publishes()/env() calls of its own, but does gate a resource behind a
    // config() read that resolves into the wrapped package's config file.
    file_put_contents($root.'/src/ShortUrlPlugin.php', <<<'PHP'
        <?php

        namespace Acme\FilamentShortUrl;

        class ShortUrlPlugin
        {
            public function panel($panel)
            {
                return $panel->resources(array_filter(
                    $this->resources,
                    fn (string $resource): bool => match ($resource) {
                        CustomDomainResource::class => (bool) config('short-url.domains.enabled', false),
                        default => true,
                    },
                ));
            }
        }
        PHP);

    mkdir($root.'/src/Widgets', recursive: true);

    // A second real gate (widget visibility, not just resource registration) —
    // must still be picked up.
    file_put_contents($root.'/src/Widgets/UsageOverview.php', <<<'PHP'
        <?php

        namespace Acme\FilamentShortUrl\Widgets;

        use Filament\Widgets\Widget;

        class UsageOverview extends Widget
        {
            public static function canView(): bool
            {
                return (bool) config('short-url.tenancy.enabled', false);
            }
        }
        PHP);

    mkdir($root.'/src/Resources/CustomDomainResource/Tables', recursive: true);

    // A config() read with NO default — a plain display/fallback value (a hostname
    // used in a table column), not a registration gate. Must NOT end up in
    // install.env, even though it resolves to a real env() call in the dependency.
    file_put_contents($root.'/src/Resources/CustomDomainResource/Tables/CustomDomainsTable.php', <<<'PHP'
        <?php

        namespace Acme\FilamentShortUrl\Resources\CustomDomainResource\Tables;

        class CustomDomainsTable
        {
            public static function fallbackDomain(): ?string
            {
                return config('short-url.route.domain') ?? parse_url(config('app.url'), PHP_URL_HOST);
            }
        }
        PHP);

    // Mirrors spatie/laravel-package-tools: no literal publishes()/publishesMigrations()
    // call anywhere — ->name(static::$name)->hasMigrations([...]) generates the
    // "{shortName}-migrations" tag internally.
    file_put_contents($root.'/vendor/acme/laravel-short-url/src/LaravelShortUrlServiceProvider.php', <<<'PHP'
        <?php

        namespace Acme\LaravelShortUrl;

        use Spatie\LaravelPackageTools\Package;
        use Spatie\LaravelPackageTools\PackageServiceProvider;

        class LaravelShortUrlServiceProvider extends PackageServiceProvider
        {
            public static string $name = 'laravel-short-url';

            public function configurePackage(Package $package): void
            {
                $package
                    ->name(static::$name)
                    ->hasConfigFile('short-url')
                    ->hasMigrations(['create_short_urls_table']);
            }
        }
        PHP);

    // Many unrelated env() reads (credentials, unrelated feature toggles) that must
    // NOT end up in install.env — only 'domains.enabled', the one the plugin's own
    // config('short-url.domains.enabled') call actually gates, should be picked up.
    file_put_contents($root.'/vendor/acme/laravel-short-url/config/short-url.php', <<<'PHP'
        <?php

        return [
            'domains' => [
                'enabled' => env('SHORT_URL_DOMAINS_ENABLED', false),
                'max_verification_failures' => env('SHORT_URL_DOMAIN_MAX_FAILURES', 10),
            ],
            'security' => [
                'maxmind_db_path' => env('SHORT_URL_MAXMIND_DB_PATH'),
                'ip_hash_salt' => env('SHORT_URL_IP_HASH_SALT'),
            ],
            'api' => [
                'enabled' => env('SHORT_URL_API_ENABLED', true),
            ],
            'tenancy' => [
                'enabled' => env('SHORT_URL_TENANCY_ENABLED', false),
            ],
            'route' => [
                'domain' => env('SHORT_URL_ROUTE_DOMAIN'),
            ],
        ];
        PHP);

    return $root;
}

it('follows publishes()/env() calls into an opted-in Composer dependency the plugin wraps', function () {
    $pluginPath = makeDependencyWrapperFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath, ['acme/laravel-short-url']);

    expect($analysis->publishTags)->toBe(['short-url-migrations'])
        ->and($analysis->envCandidates)->toBe([
            'SHORT_URL_DOMAINS_ENABLED' => 'true',
            'SHORT_URL_TENANCY_ENABLED' => 'true',
        ]);
});

it('does not scan dependencies unless explicitly opted in via $depPackages', function () {
    $pluginPath = makeDependencyWrapperFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath);

    expect($analysis->publishTags)->toBe([])
        ->and($analysis->envCandidates)->toBe([]);
});

it('does not leak unrelated env() reads from the dependency that no config() gate references', function () {
    $pluginPath = makeDependencyWrapperFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath, ['acme/laravel-short-url']);

    expect($analysis->envCandidates)->not->toHaveKey('SHORT_URL_MAXMIND_DB_PATH')
        ->and($analysis->envCandidates)->not->toHaveKey('SHORT_URL_IP_HASH_SALT')
        ->and($analysis->envCandidates)->not->toHaveKey('SHORT_URL_API_ENABLED')
        ->and($analysis->envCandidates)->not->toHaveKey('SHORT_URL_DOMAIN_MAX_FAILURES');
});

it('does not treat a config() read with no literal boolean/null default as a gate (display-value, not a toggle)', function () {
    $pluginPath = makeDependencyWrapperFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath, ['acme/laravel-short-url']);

    expect($analysis->envCandidates)->not->toHaveKey('SHORT_URL_ROUTE_DOMAIN');
});

function fieldNamed(array $fields, string $name): ?FieldInfo
{
    foreach ($fields as $field) {
        if ($field->name === $name) {
            return $field;
        }
    }

    return null;
}

function makeEnumOptionsFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-enum-'.uniqid();

    mkdir($root.'/src/Enums', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/newsletter',
        'autoload' => [
            'psr-4' => [
                'Acme\\Newsletter\\' => 'src/',
            ],
        ],
        'require' => [
            'filament/filament' => '^3.0',
        ],
    ]));

    file_put_contents($root.'/src/Enums/ContentType.php', <<<'PHP'
        <?php

        namespace Acme\Newsletter\Enums;

        enum ContentType: string
        {
            case RichText = 'rich_text';
            case Markdown = 'markdown';
        }
        PHP);

    // Mirrors jeffersongoncalves/filament-newsletter's content_type Select: the
    // options() key is an enum case's ->value, not a quoted string literal, and
    // the label is a __() translation call rather than a literal too.
    file_put_contents($root.'/src/NewsletterResource.php', <<<'PHP'
        <?php

        namespace Acme\Newsletter;

        use Filament\Forms\Form;
        use Filament\Forms\Components\Select;
        use Filament\Resources\Resource;
        use Acme\Newsletter\Enums\ContentType;

        class NewsletterResource extends Resource
        {
            protected static ?string $model = Newsletter::class;

            public static function form(Form $form): Form
            {
                return $form->schema([
                    Select::make('content_type')
                        ->options([
                            ContentType::RichText->value => __('acme.content_types.rich_text'),
                            ContentType::Markdown->value => __('acme.content_types.markdown'),
                        ]),
                    Select::make('status')
                        ->options([
                            \Acme\Newsletter\Enums\MissingEnum::Draft->value => __('acme.statuses.draft'),
                        ]),
                ]);
            }
        }
        PHP);

    return $root;
}

it('resolves an enum-backed Select option (EnumClass::Case->value => label()) to the case\'s backing value', function () {
    $pluginPath = makeEnumOptionsFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath);

    $field = fieldNamed($analysis->resources[0]->fields, 'content_type');

    expect($field->options)->toBe([
        'rich_text' => 'rich_text',
        'markdown' => 'markdown',
    ])->and($field->optionsUnresolved)->toBeFalse();
});

it('falls back to the case name when an enum-backed option\'s class file cannot be resolved, instead of dropping it', function () {
    $pluginPath = makeEnumOptionsFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath);

    $field = fieldNamed($analysis->resources[0]->fields, 'status');

    expect($field->options)->toBe(['Draft' => 'Draft']);
});

function makeTransitiveDepsFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-transitive-'.uniqid();

    mkdir($root.'/src', recursive: true);
    mkdir($root.'/vendor/acme/filament-kit/src', recursive: true);
    mkdir($root.'/vendor/acme/laravel-media/src', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/filament-newsletter',
        'autoload' => [
            'psr-4' => [
                'Acme\\FilamentNewsletter\\' => 'src/',
            ],
        ],
        'require' => [
            'filament/filament' => '^3.0',
            'acme/filament-kit' => '^1.0',
        ],
    ]));

    // The --deps package itself has no publishes()/hasMigrations() call — it just
    // wraps another package that does.
    file_put_contents($root.'/vendor/acme/filament-kit/composer.json', json_encode([
        'name' => 'acme/filament-kit',
        'require' => [
            'acme/laravel-media' => '^1.0',
        ],
    ]));

    file_put_contents($root.'/vendor/acme/filament-kit/src/KitServiceProvider.php', <<<'PHP'
        <?php

        namespace Acme\FilamentKit;

        class KitServiceProvider
        {
            public function register(): void
            {
                //
            }
        }
        PHP);

    // Two levels down from the --deps package: this is the one that actually
    // ships migrations, via spatie/laravel-package-tools' ->hasMigrations() DSL.
    file_put_contents($root.'/vendor/acme/laravel-media/composer.json', json_encode([
        'name' => 'acme/laravel-media',
        'require' => [],
    ]));

    file_put_contents($root.'/vendor/acme/laravel-media/src/MediaServiceProvider.php', <<<'PHP'
        <?php

        namespace Acme\LaravelMedia;

        use Spatie\LaravelPackageTools\Package;
        use Spatie\LaravelPackageTools\PackageServiceProvider;

        class MediaServiceProvider extends PackageServiceProvider
        {
            public static string $name = 'laravel-media';

            public function configurePackage(Package $package): void
            {
                $package
                    ->name(static::$name)
                    ->hasMigrations(['create_media_table']);
            }
        }
        PHP);

    return $root;
}

it('walks transitive Composer dependencies of a --deps package to find migrations it does not publish itself', function () {
    $pluginPath = makeTransitiveDepsFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath, ['acme/filament-kit']);

    expect($analysis->publishTags)->toBe(['media-migrations']);
});

function makeSingularHasMigrationFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-singular-migration-'.uniqid();

    mkdir($root.'/src', recursive: true);
    mkdir($root.'/vendor/spatie/laravel-medialibrary/src', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/filament-newsletter',
        'autoload' => [
            'psr-4' => [
                'Acme\\FilamentNewsletter\\' => 'src/',
            ],
        ],
        'require' => [
            'filament/filament' => '^3.0',
            'spatie/laravel-medialibrary' => '^11.0',
        ],
    ]));

    // spatie/laravel-medialibrary's real MediaLibraryServiceProvider calls the
    // *singular* ->hasMigration(), not ->hasMigrations() — must still be recognized.
    file_put_contents($root.'/vendor/spatie/laravel-medialibrary/src/MediaLibraryServiceProvider.php', <<<'PHP'
        <?php

        namespace Spatie\MediaLibrary;

        use Spatie\LaravelPackageTools\Package;
        use Spatie\LaravelPackageTools\PackageServiceProvider;

        class MediaLibraryServiceProvider extends PackageServiceProvider
        {
            public function configurePackage(Package $package): void
            {
                $package
                    ->name('laravel-medialibrary')
                    ->hasMigration('create_media_table');
            }
        }
        PHP);

    return $root;
}

it('recognizes the singular ->hasMigration() DSL call, not just the plural ->hasMigrations()', function () {
    $pluginPath = makeSingularHasMigrationFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath, ['spatie/laravel-medialibrary']);

    expect($analysis->publishTags)->toBe(['medialibrary-migrations']);
});

function makeNonFilamentPackageFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-no-plugin-'.uniqid();

    mkdir($root.'/src', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/laravel-newsletter',
        'autoload' => [
            'psr-4' => [
                'Acme\\LaravelNewsletter\\' => 'src/',
            ],
        ],
        'require' => [
            'illuminate/support' => '^11.0',
        ],
    ]));

    // A plain Laravel package with public routes/views but no Filament dependency
    // at all — no Plugin class anywhere in src/.
    file_put_contents($root.'/src/NewsletterServiceProvider.php', <<<'PHP'
        <?php

        namespace Acme\LaravelNewsletter;

        use Illuminate\Support\ServiceProvider;

        class NewsletterServiceProvider extends ServiceProvider
        {
            public function boot(): void
            {
                //
            }
        }
        PHP);

    return $root;
}

it('leaves pluginClass null instead of a fake "Unknown\\Plugin" fallback when no Filament Plugin class exists', function () {
    $pluginPath = makeNonFilamentPackageFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath);

    expect($analysis->pluginClass)->toBeNull();
});

function makeRoutesFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-routes-'.uniqid();

    mkdir($root.'/src/Http/Controllers', recursive: true);
    mkdir($root.'/routes', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/newsletter',
        'autoload' => [
            'psr-4' => [
                'Acme\\Newsletter\\' => 'src/',
            ],
        ],
        'require' => [],
    ]));

    // Mirrors jeffersongoncalves/laravel-newsletter's routes/web.php: a grouped
    // prefix+name, a plain named route, a signed route-model-bound route inside
    // the group, and an unrelated auth-gated route outside it.
    file_put_contents($root.'/routes/web.php', <<<'PHP'
        <?php

        use Illuminate\Support\Facades\Route;
        use Acme\Newsletter\Http\Controllers\SubscriptionController;

        Route::prefix('newsletter')
            ->name('newsletter.')
            ->middleware('web')
            ->group(function (): void {
                Route::get('subscribe', [SubscriptionController::class, 'showForm'])
                    ->name('subscribe.form');

                Route::get('confirm/{emailGroupMember}', [SubscriptionController::class, 'confirm'])
                    ->middleware('signed')
                    ->name('confirm');
            });

        Route::get('admin/dashboard', [SubscriptionController::class, 'dashboard'])
            ->middleware('auth')
            ->name('admin.dashboard');
        PHP);

    file_put_contents($root.'/src/Http/Controllers/SubscriptionController.php', <<<'PHP'
        <?php

        namespace Acme\Newsletter\Http\Controllers;

        use Acme\Newsletter\Models\EmailGroupMember;

        class SubscriptionController
        {
            public function showForm()
            {
                //
            }

            public function confirm(EmailGroupMember $emailGroupMember)
            {
                //
            }

            public function dashboard()
            {
                //
            }
        }
        PHP);

    return $root;
}

it('detects named routes from routes/*.php, including group name/middleware inheritance and route-model-binding', function () {
    $pluginPath = makeRoutesFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath);

    $byName = [];
    foreach ($analysis->routes as $route) {
        $byName[$route->name] = $route;
    }

    expect($byName)->toHaveKeys(['newsletter.subscribe.form', 'newsletter.confirm', 'admin.dashboard']);

    expect($byName['newsletter.subscribe.form']->signed)->toBeFalse()
        ->and($byName['newsletter.subscribe.form']->auth)->toBeFalse()
        ->and($byName['newsletter.subscribe.form']->params)->toBe([]);

    expect($byName['newsletter.confirm']->signed)->toBeTrue()
        ->and($byName['newsletter.confirm']->auth)->toBeFalse()
        ->and($byName['newsletter.confirm']->params)->toBe(['emailGroupMember'])
        ->and($byName['newsletter.confirm']->paramModels)->toBe([
            'emailGroupMember' => 'Acme\\Newsletter\\Models\\EmailGroupMember',
        ]);

    expect($byName['admin.dashboard']->auth)->toBeTrue();
});

function makeRelationManagerFixturePlugin(): string
{
    $root = sys_get_temp_dir().'/screentest-fixture-relation-manager-'.uniqid();

    mkdir($root.'/src/Resources/EmailGroupResource/RelationManagers', recursive: true);
    mkdir($root.'/vendor/acme/laravel-newsletter/src/Models', recursive: true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/filament-newsletter',
        'autoload' => [
            'psr-4' => [
                'Acme\\FilamentNewsletter\\' => 'src/',
            ],
        ],
        'require' => [
            'filament/filament' => '^3.0',
            'acme/laravel-newsletter' => '^1.0',
        ],
    ]));

    // Mirrors jeffersongoncalves/filament-newsletter's EmailGroupResource exactly:
    // a RelationManager tab with no Select field and no Resource of its own for the
    // related model.
    file_put_contents($root.'/src/Resources/EmailGroupResource.php', <<<'PHP'
        <?php

        namespace Acme\FilamentNewsletter\Resources;

        use Filament\Resources\Resource;
        use Acme\FilamentNewsletter\Resources\EmailGroupResource\RelationManagers\MembersRelationManager;

        class EmailGroupResource extends Resource
        {
            protected static ?string $model = \Acme\LaravelNewsletter\Models\EmailGroup::class;

            public static function getRelations(): array
            {
                return [
                    MembersRelationManager::class,
                ];
            }
        }
        PHP);

    file_put_contents($root.'/src/Resources/EmailGroupResource/RelationManagers/MembersRelationManager.php', <<<'PHP'
        <?php

        namespace Acme\FilamentNewsletter\Resources\EmailGroupResource\RelationManagers;

        use Filament\Resources\RelationManagers\RelationManager;

        class MembersRelationManager extends RelationManager
        {
            protected static string $relationship = 'members';
        }
        PHP);

    // The relationship (and therefore the related model) is only declared on the
    // OWNER model, which lives in the wrapped --deps package, not the plugin itself.
    file_put_contents($root.'/vendor/acme/laravel-newsletter/src/Models/EmailGroup.php', <<<'PHP'
        <?php

        namespace Acme\LaravelNewsletter\Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\HasMany;

        class EmailGroup extends Model
        {
            public function members(): HasMany
            {
                return $this->hasMany(EmailGroupMember::class);
            }
        }
        PHP);

    return $root;
}

it('detects a model only reachable via a RelationManager tab by resolving its $relationship into the owner model\'s relation method', function () {
    $pluginPath = makeRelationManagerFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath, ['acme/laravel-newsletter']);

    expect($analysis->relationModels)->toBe(['Acme\\LaravelNewsletter\\Models\\EmailGroupMember']);
});

it('does not detect relation-manager models without --deps, since the owner model lives in the wrapped package', function () {
    $pluginPath = makeRelationManagerFixturePlugin();

    $analysis = (new PluginAnalyzerService)->analyze($pluginPath);

    expect($analysis->relationModels)->toBe([]);
});
