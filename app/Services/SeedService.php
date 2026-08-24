<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\FieldInfo;
use App\DTOs\ModelSeedConfig;
use App\DTOs\ResourceInfo;
use App\DTOs\ScreentestConfig;
use App\DTOs\SeedConfig;
use App\DTOs\UserConfig;

class SeedService
{
    /** @var string[] */
    protected array $warnings = [];

    public function __construct(
        protected ProcessService $process,
        protected PluginAnalyzerService $analyzer,
    ) {}

    /**
     * @return string[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function generateAndRun(ScreentestConfig $config, string $projectPath, string $pluginPath): void
    {
        $this->warnings = [];
        $seederClasses = [];

        // 1. Generate user seeder
        $this->generateUserSeeder($config->seed->user, $projectPath);
        $seederClasses[] = 'ScreentestUserSeeder';

        // 2. Generate seeders for explicitly defined models first — explicit
        // config (in particular per-model `attributes`) must win over
        // auto-detection, never get silently dropped because auto-detect
        // already claimed that model's seeder class name.
        foreach ($config->seed->models as $modelSeedConfig) {
            /** @var ModelSeedConfig $modelSeedConfig */
            $modelShort = class_basename($modelSeedConfig->model);
            $seederClass = "Screentest{$modelShort}Seeder";

            if (in_array($seederClass, $seederClasses, true)) {
                continue;
            }

            $this->generateExplicitModelSeeder($modelSeedConfig, $projectPath);
            $seederClasses[] = $seederClass;
        }

        // 3. Auto-detect resources and generate factories/seeders for
        // anything not already explicitly configured above.
        if ($config->seed->autoDetect) {
            $analysis = $this->analyzer->analyze($pluginPath);
            $orderedResources = $this->orderByDependency($analysis->resources);
            $autoSeederClasses = $this->generateModelSeeders($orderedResources, $config->seed, $projectPath, $seederClasses);
            $seederClasses = array_merge($seederClasses, $autoSeederClasses);
        }

        // 4. Create master ScreentestSeeder
        $this->generateMasterSeeder($seederClasses, $projectPath);

        // 5. Run the master seeder
        $this->process->runOrFail(
            $this->process->phpBinary().' artisan db:seed --class=ScreentestSeeder --force',
            $projectPath,
        );
    }

    protected function generateUserSeeder(UserConfig $user, string $projectPath): void
    {
        $content = <<<PHP
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ScreentestUserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name' => '{$user->name}',
            'email' => '{$user->email}',
            'password' => Hash::make('{$user->password}'),
        ]);
    }
}
PHP;

        $seederPath = $projectPath.'/database/seeders/ScreentestUserSeeder.php';
        $this->ensureDirectory(dirname($seederPath));
        file_put_contents($seederPath, $content);
    }

    /**
     * @param  ResourceInfo[]  $resources
     * @param  string[]  $reservedSeederClasses  seeder classes already generated explicitly — skip these
     * @return string[]
     */
    protected function generateModelSeeders(array $resources, SeedConfig $config, string $projectPath, array $reservedSeederClasses = []): array
    {
        $seederClasses = [];

        foreach ($resources as $resource) {
            $modelShort = $resource->modelShortName;
            $modelClass = $resource->model;

            if (in_array("Screentest{$modelShort}Seeder", $reservedSeederClasses, true)) {
                continue;
            }

            // Find explicit count for this model, default to 10
            $count = 10;
            foreach ($config->models as $modelSeedConfig) {
                /** @var ModelSeedConfig $modelSeedConfig */
                if ($modelSeedConfig->model === $modelClass) {
                    $count = $modelSeedConfig->count;
                    break;
                }
            }

            // Generate factory if it doesn't already exist
            $factoryPath = $projectPath."/database/factories/{$modelShort}Factory.php";
            if (! file_exists($factoryPath)) {
                $this->generateFactory($resource, $projectPath);
            }

            // Generate seeder
            $this->generateSeeder($modelClass, $modelShort, $count, $projectPath);
            $seederClasses[] = "Screentest{$modelShort}Seeder";
        }

        return $seederClasses;
    }

    protected function generateFactory(ResourceInfo $resource, string $projectPath): void
    {
        $modelShort = $resource->modelShortName;
        $modelClass = $resource->model;
        $fieldsDefinition = $this->buildFactoryDefinition($resource);

        $stub = file_get_contents(__DIR__.'/../../stubs/factory.php.stub');

        $content = str_replace(
            ['{{MODEL_CLASS}}', '{{MODEL_SHORT}}', '{{FIELDS}}'],
            [$modelClass, $modelShort, $fieldsDefinition],
            $stub,
        );

        $factoryPath = $projectPath."/database/factories/{$modelShort}Factory.php";
        $this->ensureDirectory(dirname($factoryPath));
        file_put_contents($factoryPath, $content);
    }

    protected function generateSeeder(string $modelClass, string $modelShort, int $count, string $projectPath): void
    {
        $seederClass = "Screentest{$modelShort}Seeder";

        $stub = file_get_contents(__DIR__.'/../../stubs/seeder.php.stub');

        $content = str_replace(
            ['{{MODEL_CLASS}}', '{{MODEL_SHORT}}', '{{SEEDER_CLASS}}', '{{COUNT}}'],
            [$modelClass, $modelShort, $seederClass, (string) $count],
            $stub,
        );

        $seederPath = $projectPath."/database/seeders/{$seederClass}.php";
        $this->ensureDirectory(dirname($seederPath));
        file_put_contents($seederPath, $content);
    }

    protected function generateExplicitModelSeeder(ModelSeedConfig $modelSeedConfig, string $projectPath): void
    {
        $modelShort = class_basename($modelSeedConfig->model);
        $seederClass = "Screentest{$modelShort}Seeder";

        if ($modelSeedConfig->attributes !== null) {
            $attributesCode = $this->buildAttributesArray($modelSeedConfig->attributes);

            $content = <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use {$modelSeedConfig->model};

class {$seederClass} extends Seeder
{
    public function run(): void
    {
        {$modelShort}::factory()->count({$modelSeedConfig->count})->create({$attributesCode});
    }
}
PHP;
        } else {
            $stub = file_get_contents(__DIR__.'/../../stubs/seeder.php.stub');

            $content = str_replace(
                ['{{MODEL_CLASS}}', '{{MODEL_SHORT}}', '{{SEEDER_CLASS}}', '{{COUNT}}'],
                [$modelSeedConfig->model, $modelShort, $seederClass, (string) $modelSeedConfig->count],
                $stub,
            );
        }

        $seederPath = $projectPath."/database/seeders/{$seederClass}.php";
        $this->ensureDirectory(dirname($seederPath));
        file_put_contents($seederPath, $content);
    }

    /**
     * @param  string[]  $seederClasses
     */
    protected function generateMasterSeeder(array $seederClasses, string $projectPath): void
    {
        $calls = implode("\n", array_map(
            fn (string $class) => "            {$class}::class,",
            $seederClasses,
        ));

        $content = <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ScreentestSeeder extends Seeder
{
    public function run(): void
    {
        \$this->call([
{$calls}
        ]);
    }
}
PHP;

        $seederPath = $projectPath.'/database/seeders/ScreentestSeeder.php';
        $this->ensureDirectory(dirname($seederPath));
        file_put_contents($seederPath, $content);
    }

    protected function buildFactoryDefinition(ResourceInfo $resource): string
    {
        $lines = [];

        foreach ($resource->fields as $field) {
            /** @var FieldInfo $field */
            $fakerExpression = $this->mapFieldToFaker($field);

            if ($fakerExpression === null) {
                continue;
            }

            $lines[] = "            '{$field->name}' => {$fakerExpression},";
        }

        return implode("\n", $lines);
    }

    protected function mapFieldToFaker(FieldInfo $field): ?string
    {
        return match ($field->component) {
            'TextInput' => $this->mapTextInputToFaker($field),
            'Textarea' => '$this->faker->paragraph()',
            'RichEditor' => "'<p>' . \$this->faker->paragraph() . '</p>'",
            'Toggle', 'Checkbox' => '$this->faker->boolean()',
            'Select' => $this->mapSelectToFaker($field),
            'DatePicker' => '$this->faker->date()',
            'DateTimePicker' => '$this->faker->dateTime()',
            'ColorPicker' => '$this->faker->hexColor()',
            'FileUpload' => null,
            default => '$this->faker->word()',
        };
    }

    protected function mapTextInputToFaker(FieldInfo $field): string
    {
        $name = strtolower($field->name);

        if (str_contains($name, 'email')) {
            return '$this->faker->safeEmail()';
        }

        if (str_contains($name, 'name')) {
            return '$this->faker->name()';
        }

        if (str_contains($name, 'title')) {
            return '$this->faker->sentence(4)';
        }

        if (str_contains($name, 'phone')) {
            return '$this->faker->phoneNumber()';
        }

        if (str_contains($name, 'url') || str_contains($name, 'website')) {
            return '$this->faker->url()';
        }

        if ($field->isNumeric) {
            return '$this->faker->numberBetween(0, 100)';
        }

        return '$this->faker->word()';
    }

    protected function mapSelectToFaker(FieldInfo $field): string
    {
        if ($field->relationModel !== null) {
            return "\\{$field->relationModel}::factory()";
        }

        if ($field->options !== null && count($field->options) > 0) {
            // The array keys are the actual stored values (e.g. an enum's backing
            // value or a plain option key) — the labels are display text only and
            // don't matter for what gets written to the database.
            $optionsList = implode("', '", array_keys($field->options));

            return "\$this->faker->randomElement(['{$optionsList}'])";
        }

        if ($field->optionsUnresolved) {
            $this->warnings[] = "could not determine valid options for '{$field->name}', defaulting to a free-text word — likely to write an invalid value";
        }

        return '$this->faker->word()';
    }

    /**
     * @param  ResourceInfo[]  $resources
     * @return ResourceInfo[]
     */
    protected function orderByDependency(array $resources): array
    {
        $modelMap = [];
        foreach ($resources as $resource) {
            $modelMap[$resource->model] = $resource;
        }

        // Build dependency graph
        $dependencies = [];
        foreach ($resources as $resource) {
            $deps = [];
            foreach ($resource->fields as $field) {
                /** @var FieldInfo $field */
                if ($field->component === 'Select' && $field->relationModel !== null) {
                    if (isset($modelMap[$field->relationModel])) {
                        $deps[] = $field->relationModel;
                    }
                }

                // Also check for FK fields ending with _id
                if (str_ends_with($field->name, '_id')) {
                    $possibleModel = str_replace('_id', '', $field->name);
                    foreach (array_keys($modelMap) as $modelClass) {
                        if (strtolower(class_basename($modelClass)) === str_replace('_', '', $possibleModel)) {
                            $deps[] = $modelClass;
                        }
                    }
                }
            }
            $dependencies[$resource->model] = array_unique($deps);
        }

        // Topological sort (Kahn's algorithm)
        $sorted = [];
        $visited = [];

        $visit = function (string $model) use (&$visit, &$sorted, &$visited, $dependencies, $modelMap): void {
            if (isset($visited[$model])) {
                return;
            }

            $visited[$model] = true;

            foreach ($dependencies[$model] ?? [] as $dep) {
                $visit($dep);
            }

            if (isset($modelMap[$model])) {
                $sorted[] = $modelMap[$model];
            }
        };

        foreach (array_keys($modelMap) as $model) {
            $visit($model);
        }

        return $sorted;
    }

    protected function buildAttributesArray(array $attributes): string
    {
        $pairs = [];
        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $pairs[] = "'{$key}' => '{$value}'";
            } elseif (is_bool($value)) {
                $pairs[] = "'{$key}' => ".($value ? 'true' : 'false');
            } elseif (is_numeric($value)) {
                $pairs[] = "'{$key}' => {$value}";
            } elseif (is_null($value)) {
                $pairs[] = "'{$key}' => null";
            } else {
                $pairs[] = "'{$key}' => '{$value}'";
            }
        }

        return '['.implode(', ', $pairs).']';
    }

    protected function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
