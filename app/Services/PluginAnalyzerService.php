<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\FieldInfo;
use App\DTOs\PluginAnalysis;
use App\DTOs\ResourceInfo;
use App\Enums\FilamentVersion;
use Symfony\Component\Finder\Finder;

class PluginAnalyzerService
{
    public function analyze(string $pluginPath): PluginAnalysis
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
        $resources = $this->detectResources($pluginPath);

        return new PluginAnalysis(
            pluginClass: $pluginClass ?? 'Unknown\\Plugin',
            package: $package,
            filamentVersion: $filamentVersion,
            resources: $resources,
        );
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
     * @return ResourceInfo[]
     */
    protected function detectResources(string $pluginPath): array
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
