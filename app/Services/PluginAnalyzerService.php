<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\FieldInfo;
use App\DTOs\PageInfo;
use App\DTOs\PluginAnalysis;
use App\DTOs\ResourceInfo;
use App\Enums\FilamentVersion;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class PluginAnalyzerService
{
    /**
     * @param  string[]  $depPackages  Composer package names (e.g. "vendor/package") whose
     *                                 installed code under vendor/ should also be scanned for
     *                                 publishes()/env() calls the plugin itself merely wraps.
     */
    public function analyze(string $pluginPath, array $depPackages = []): PluginAnalysis
    {
        $composerPath = $pluginPath.'/composer.json';

        if (! file_exists($composerPath)) {
            throw new \RuntimeException("No composer.json found at: {$pluginPath}");
        }

        $composerData = json_decode(file_get_contents($composerPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid composer.json: '.json_last_error_msg());
        }

        $pluginClass = $this->detectPluginClass($pluginPath);
        $package = $composerData['name'] ?? 'unknown/unknown';
        $filamentVersion = $this->detectFilamentVersion($composerData);
        $psr4 = $composerData['autoload']['psr-4'] ?? [];
        $resources = $this->detectResources($pluginPath, $psr4);
        $pages = $this->detectPages($pluginPath);
        $publishTags = $this->detectDependencyPublishTags($pluginPath, $depPackages);
        $envCandidates = $this->detectDependencyEnvFlags($pluginPath, $depPackages);

        return new PluginAnalysis(
            pluginClass: $pluginClass ?? 'Unknown\\Plugin',
            package: $package,
            filamentVersion: $filamentVersion,
            resources: $resources,
            pages: $pages,
            publishTags: $publishTags,
            envCandidates: $envCandidates,
        );
    }

    /**
     * Scan `publishes()`/`publishesMigrations()` calls inside the given Composer
     * dependencies' installed code (vendor/<package>) for their publish tags, since
     * a Filament plugin that merely wraps another package (e.g. a "kit" plugin around
     * a standalone service package) declares no `publishes()` of its own.
     *
     * @param  string[]  $depPackages
     * @return string[]
     */
    protected function detectDependencyPublishTags(string $pluginPath, array $depPackages): array
    {
        $tags = [];

        foreach ($this->findDependencyFiles($pluginPath, $depPackages) as $file) {
            $content = $file->getContents();

            if (! preg_match_all('/publishes(?:Migrations)?\s*\(/', $content, $callMatches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($callMatches[0] as $callMatch) {
                $openParenOffset = $callMatch[1] + strlen($callMatch[0]) - 1;
                $args = $this->extractParenthesizedBody($content, $openParenOffset);

                if (preg_match('/,\s*[\'"]([A-Za-z0-9_\-]+)[\'"]\s*,?\s*$/', rtrim($args), $tagMatch)) {
                    $tags[] = $tagMatch[1];
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Scan `env('KEY', default)` reads inside the given Composer dependencies' installed
     * code for flags that default to a falsy value, since those are the ones a plugin
     * needs enabled (via `install.env`) for the dependency's config-gated resources/pages
     * to register at all.
     *
     * @param  string[]  $depPackages
     * @return array<string, string>
     */
    protected function detectDependencyEnvFlags(string $pluginPath, array $depPackages): array
    {
        $flags = [];

        foreach ($this->findDependencyFiles($pluginPath, $depPackages) as $file) {
            $content = $file->getContents();

            if (! preg_match_all(
                '/env\s*\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]\s*(?:,\s*(true|false|null|\d+))?\s*\)/',
                $content,
                $matches,
                PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($matches as $match) {
                $key = $match[1];
                $default = strtolower($match[2] ?? 'null');

                // Already enabled by default (or a non-boolean default we can't reason
                // about) — nothing for `install.env` to override.
                if ($default === 'true' || (ctype_digit($default) && (int) $default !== 0)) {
                    continue;
                }

                $flags[$key] = 'true';
            }
        }

        return $flags;
    }

    /**
     * @param  string[]  $depPackages
     * @return iterable<SplFileInfo>
     */
    private function findDependencyFiles(string $pluginPath, array $depPackages): iterable
    {
        if ($depPackages === []) {
            return [];
        }

        $files = [];

        foreach ($depPackages as $depPackage) {
            $depPath = rtrim($pluginPath, '/').'/vendor/'.trim($depPackage, '/');

            if (! is_dir($depPath)) {
                continue;
            }

            $finder = new Finder;
            $finder->files()->in($depPath)->name('*.php')->exclude(['tests', 'Tests', 'vendor']);

            foreach ($finder as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Given the offset of an opening `(`, return the content between it and its
     * matching closing `)` (depth aware, so nested calls don't confuse it).
     */
    private function extractParenthesizedBody(string $content, int $openParenOffset): string
    {
        $depth = 0;
        $length = strlen($content);

        for ($i = $openParenOffset; $i < $length; $i++) {
            if ($content[$i] === '(') {
                $depth++;
            } elseif ($content[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($content, $openParenOffset + 1, $i - $openParenOffset - 1);
                }
            }
        }

        return substr($content, $openParenOffset + 1);
    }

    /**
     * Detect the main plugin class by scanning for files that extend a Filament Plugin base class
     * or implement the Plugin interface.
     */
    protected function detectPluginClass(string $pluginPath): ?string
    {
        $srcPath = $pluginPath.'/src';

        if (! is_dir($srcPath)) {
            return null;
        }

        $finder = new Finder;
        $finder->files()->in($srcPath)->name('*.php')->sortByName();

        foreach ($finder as $file) {
            $content = $file->getContents();

            // Match classes that extend a Filament Plugin base or implement Plugin interface
            if (preg_match('/extends\s+\\\\?Filament\\\\[A-Za-z\\\\]*Plugin\b/', $content)
                || preg_match('/implements\s+[A-Za-z\\\\]*Plugin\b/', $content)
                || preg_match('/implements\s+\\\\?Filament\\\\Contracts\\\\Plugin\b/', $content)) {

                // Extract the fully qualified class name from namespace + class declaration
                $namespace = null;
                $className = null;

                if (preg_match('/namespace\s+([A-Za-z0-9\\\\]+)\s*;/', $content, $nsMatch)) {
                    $namespace = $nsMatch[1];
                }

                if (preg_match('/class\s+([A-Za-z0-9_]+)\s+/', $content, $classMatch)) {
                    $className = $classMatch[1];
                }

                if ($namespace && $className) {
                    return $namespace.'\\'.$className;
                }

                if ($className) {
                    return $className;
                }
            }
        }

        return null;
    }

    /**
     * Detect Filament Resource classes within the plugin's src directory.
     *
     * @param  array<string, string>  $psr4
     * @return ResourceInfo[]
     */
    protected function detectResources(string $pluginPath, array $psr4 = []): array
    {
        $srcPath = $pluginPath.'/src';

        if (! is_dir($srcPath)) {
            return [];
        }

        $finder = new Finder;
        $finder->files()->in($srcPath)->name('*Resource.php')->sortByName();

        $resources = [];

        foreach ($finder as $file) {
            $content = $file->getContents();

            // Only process files that extend a Resource base class
            if (! preg_match('/extends\s+[A-Za-z\\\\]*Resource\b/', $content)) {
                continue;
            }

            // Skip RelationManager classes that happen to end with Resource
            if (preg_match('/extends\s+[A-Za-z\\\\]*RelationManager\b/', $content)) {
                continue;
            }

            $namespace = null;
            $className = null;

            if (preg_match('/namespace\s+([A-Za-z0-9\\\\]+)\s*;/', $content, $nsMatch)) {
                $namespace = $nsMatch[1];
            }

            if (preg_match('/class\s+([A-Za-z0-9_]+)\s+/', $content, $classMatch)) {
                $className = $classMatch[1];
            }

            if (! $className) {
                continue;
            }

            $fqcn = $namespace ? $namespace.'\\'.$className : $className;
            $model = $this->extractModel($content);
            $modelShortName = $model ? class_basename($model) : str_replace('Resource', '', $className);
            $fields = $this->parseResourceFields($file->getRealPath());

            // Most Filament plugins split the form schema into a dedicated
            // `SomethingForm::configure($schema)` class rather than inlining
            // field components directly in the Resource — follow that one
            // level of delegation so `fields` isn't empty for those plugins.
            $delegatedFields = $this->resolveDelegatedFormFields($content, $pluginPath, $psr4);

            $byName = [];
            foreach ([...$fields, ...$delegatedFields] as $field) {
                $byName[$field->name] = $field;
            }
            $fields = array_values($byName);

            $resources[] = new ResourceInfo(
                class: $fqcn,
                model: $model ?? 'App\\Models\\'.$modelShortName,
                modelShortName: $modelShortName,
                fields: $fields,
            );
        }

        return $resources;
    }

    /**
     * Detect standalone Filament Page classes (settings/metrics/import-style pages
     * registered via `$panel->pages([...])`, as opposed to a Resource's own
     * List/Create/Edit pages, which live under `<Resource>/Pages/` and extend
     * ListRecords/CreateRecord/EditRecord rather than the plain Page class).
     *
     * @return PageInfo[]
     */
    protected function detectPages(string $pluginPath): array
    {
        $srcPath = $pluginPath.'/src';

        if (! is_dir($srcPath)) {
            return [];
        }

        $finder = new Finder;
        $finder->files()->in($srcPath)->name('*.php')->sortByName();

        $pages = [];

        foreach ($finder as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            // A Resource's own pages live under Resources/<X>Resource/Pages/ and
            // extend ListRecords/CreateRecord/EditRecord, not the plain Page class.
            if (str_contains($relativePath, 'Resources/')) {
                continue;
            }

            $content = $file->getContents();

            if (! preg_match('/extends\s+[A-Za-z0-9\\\\]*Page\b/', $content)) {
                continue;
            }

            $namespace = null;
            $className = null;

            if (preg_match('/namespace\s+([A-Za-z0-9\\\\]+)\s*;/', $content, $nsMatch)) {
                $namespace = $nsMatch[1];
            }

            if (preg_match('/class\s+([A-Za-z0-9_]+)\s+/', $content, $classMatch)) {
                $className = $classMatch[1];
            }

            if (! $className) {
                continue;
            }

            $fqcn = $namespace ? $namespace.'\\'.$className : $className;

            // An explicit `protected static ?string $slug = '...'` override wins;
            // otherwise this mirrors Filament's own default: kebab-case of the
            // class basename (e.g. SettingsPage -> settings-page).
            if (preg_match('/protected\s+static\s+\??\s*string\s+\$slug\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $slugMatch)) {
                $slug = $slugMatch[1];
            } else {
                $slug = $this->kebabCase($className);
            }

            $pages[] = new PageInfo(class: $fqcn, name: $className, slug: $slug);
        }

        return $pages;
    }

    private function kebabCase(string $value): string
    {
        return strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $value));
    }

    /**
     * Follow a `SomeForm::configure($schema)` (or `SomeForm::form($schema)`) call inside
     * a Resource's `form()` method to the class it delegates to, and parse fields from
     * that class's file too. Handles both fully-qualified and `use`-imported short names.
     *
     * @param  array<string, string>  $psr4
     * @return FieldInfo[]
     */
    protected function resolveDelegatedFormFields(string $resourceContent, string $pluginPath, array $psr4): array
    {
        if ($psr4 === []) {
            return [];
        }

        if (! preg_match('/function\s+form\s*\([^)]*\)[^{]*\{/', $resourceContent, $formMatch, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $formBody = $this->extractBracedBody($resourceContent, $formMatch[0][1] + strlen($formMatch[0][0]) - 1);

        if (! preg_match('/([A-Za-z0-9_\\\\]+)::(?:configure|form)\s*\(/', $formBody, $callMatch)) {
            return [];
        }

        $reference = $callMatch[1];
        $fqcn = $this->resolveClassReference($reference, $resourceContent);

        if ($fqcn === null) {
            return [];
        }

        $path = $this->fqcnToPath($fqcn, $pluginPath, $psr4);

        if ($path === null || ! is_file($path)) {
            return [];
        }

        return $this->parseResourceFields($path);
    }

    /**
     * Resolve a class reference used in a method call (e.g. "ApiKeyForm" from
     * `ApiKeyForm::configure(...)`) to a fully-qualified class name, using the
     * containing file's `use` imports when the reference isn't already qualified.
     */
    private function resolveClassReference(string $reference, string $fileContent): ?string
    {
        $reference = ltrim($reference, '\\');

        if (str_contains($reference, '\\')) {
            return $reference;
        }

        if (preg_match('/use\s+([A-Za-z0-9_\\\\]+\\\\'.preg_quote($reference, '/').')\s*;/', $fileContent, $useMatch)) {
            return $useMatch[1];
        }

        return null;
    }

    /**
     * Resolve a fully-qualified class name to an absolute file path using a
     * PSR-4 `namespace prefix => directory` map from composer.json, matching
     * the longest applicable prefix (as Composer's own autoloader does).
     *
     * @param  array<string, string>  $psr4
     */
    private function fqcnToPath(string $fqcn, string $pluginPath, array $psr4): ?string
    {
        $fqcn = ltrim($fqcn, '\\');
        $bestPrefix = null;
        $bestDir = null;

        foreach ($psr4 as $prefix => $dir) {
            if (! str_starts_with($fqcn, $prefix)) {
                continue;
            }

            if ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix)) {
                $bestPrefix = $prefix;
                $bestDir = $dir;
            }
        }

        if ($bestPrefix === null || $bestDir === null) {
            return null;
        }

        $relativeClass = substr($fqcn, strlen($bestPrefix));
        $relativePath = str_replace('\\', '/', $relativeClass).'.php';

        return rtrim($pluginPath, '/').'/'.trim($bestDir, '/').'/'.$relativePath;
    }

    /**
     * Given the offset of an opening `{`, return the content between it and its
     * matching closing `}` (brace-depth aware, so nested blocks don't confuse it).
     */
    private function extractBracedBody(string $content, int $openBraceOffset): string
    {
        $depth = 0;
        $length = strlen($content);

        for ($i = $openBraceOffset; $i < $length; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($content, $openBraceOffset + 1, $i - $openBraceOffset - 1);
                }
            }
        }

        return substr($content, $openBraceOffset + 1);
    }

    /**
     * Parse a Resource file to extract Filament field components from the form() method.
     *
     * @return FieldInfo[]
     */
    protected function parseResourceFields(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return [];
        }

        $fields = [];

        // Match Filament field component patterns like TextInput::make('name')
        $componentPattern = '/(?<component>TextInput|Textarea|RichEditor|Toggle|Checkbox|Select|DatePicker|DateTimePicker|ColorPicker|FileUpload|KeyValue|Repeater|Hidden|MarkdownEditor|TagsInput)::make\(\s*[\'"](?<name>[A-Za-z0-9_.]+)[\'"]\s*\)/';

        if (! preg_match_all($componentPattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $fields;
        }

        foreach ($matches as $match) {
            $component = $match['component'][0];
            $name = $match['name'][0];
            $offset = $match[0][1];

            // Extract the full method chain following this component (up to a reasonable boundary)
            $chainContent = $this->extractMethodChain($content, $offset);

            $isNumeric = (bool) preg_match('/->numeric\s*\(/', $chainContent);
            $isRequired = (bool) preg_match('/->required\s*\(/', $chainContent);

            // Detect FK relation for Select fields with name ending in _id
            $relationModel = null;
            if ($component === 'Select' && str_ends_with($name, '_id')) {
                $relationName = str_replace('_id', '', $name);
                $relationModel = 'App\\Models\\'.ucfirst($relationName);
            }

            // Extract options array for Select fields
            $options = null;
            if ($component === 'Select' && preg_match('/->options\s*\(\s*\[([^\]]*)\]\s*\)/', $chainContent, $optMatch)) {
                $options = $this->parseOptionsArray($optMatch[1]);
            }

            $fields[] = new FieldInfo(
                name: $name,
                component: $component,
                isNumeric: $isNumeric,
                isRequired: $isRequired,
                relationModel: $relationModel,
                options: $options,
            );
        }

        return $fields;
    }

    /**
     * Extract the model class from a Resource file's $model property.
     */
    protected function extractModel(string $content): ?string
    {
        // Match: protected static ?string $model = ModelClass::class;
        if (preg_match('/protected\s+static\s+\??\s*string\s+\$model\s*=\s*([A-Za-z0-9\\\\]+)::class\s*;/', $content, $match)) {
            $model = $match[1];

            // If it's a short class name, try to resolve from use statements
            if (! str_contains($model, '\\')) {
                if (preg_match('/use\s+([A-Za-z0-9\\\\]+\\\\'.preg_quote($model, '/').')\s*;/', $content, $useMatch)) {
                    return $useMatch[1];
                }
            }

            return $model;
        }

        return null;
    }

    /**
     * Detect the Filament version from composer.json require/require-dev sections.
     */
    protected function detectFilamentVersion(array $composerData): ?FilamentVersion
    {
        // Check in require first, then require-dev
        $sections = ['require', 'require-dev'];
        $filamentPackages = [
            'filament/filament',
            'filament/support',
            'filament/forms',
            'filament/tables',
            'filament/panels',
        ];

        foreach ($sections as $section) {
            if (! isset($composerData[$section])) {
                continue;
            }

            foreach ($filamentPackages as $package) {
                if (isset($composerData[$section][$package])) {
                    $constraint = $composerData[$section][$package];

                    try {
                        return FilamentVersion::fromComposerConstraint($constraint);
                    } catch (\ValueError) {
                        continue;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract the method chain content following a component declaration.
     * This reads forward from the match offset until we hit a line that likely ends the chain.
     */
    private function extractMethodChain(string $content, int $offset): string
    {
        // Get a reasonable chunk of content after the match (up to 2000 chars)
        $chunk = substr($content, $offset, 2000);

        // Find the end of the method chain by tracking nesting depth
        $depth = 0;
        $length = strlen($chunk);
        $end = $length;

        for ($i = 0; $i < $length; $i++) {
            $char = $chunk[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth <= 0) {
                    // Check if followed by -> (chain continues) or end of chain
                    $rest = ltrim(substr($chunk, $i + 1));
                    if (! str_starts_with($rest, '->')) {
                        $end = $i + 1;
                        break;
                    }
                }
            } elseif ($depth === 0 && ($char === ',' || $char === ']')) {
                // End of this field in the schema array
                $end = $i;
                break;
            }
        }

        return substr($chunk, 0, $end);
    }

    /**
     * Parse a simple options array string like "'draft' => 'Draft', 'published' => 'Published'"
     * into an associative array.
     *
     * @return array<string, string>
     */
    private function parseOptionsArray(string $optionsString): array
    {
        $options = [];

        // Match key => value pairs with string keys and values
        if (preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $optionsString, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) {
                $options[$pair[1]] = $pair[2];
            }
        }

        return $options;
    }
}
