<?php

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
