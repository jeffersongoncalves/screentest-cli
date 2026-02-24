<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CaptureResult;
use App\DTOs\ScreentestConfig;

class ReadmeService
{
    public function update(ScreentestConfig $config, string $pluginPath, array $results): void
    {
        if (! $config->readme->update) {
            return;
        }

        $readmePath = $pluginPath.'/README.md';

        if (! file_exists($readmePath)) {
            return;
        }

        $content = file_get_contents($readmePath);
        $marker = $config->readme->sectionMarker;

        if (! str_contains($content, $marker)) {
            return;
        }

        $section = $this->generateSection($config, $results);
        $content = $this->replaceSection($content, $marker, $section);

        file_put_contents($readmePath, $content);
    }

    protected function generateSection(ScreentestConfig $config, array $results): string
    {
        $successfulResults = array_filter($results, fn (CaptureResult $r) => $r->success);

        if (empty($successfulResults)) {
            return '';
        }

        return match ($config->readme->template) {
            'gallery' => $this->generateGallery($config, $successfulResults),
            default => $this->generateTable($config, $successfulResults),
        };
    }

    protected function generateTable(ScreentestConfig $config, array $results): string
    {
        $grouped = $this->groupByName($results);
        $themes = array_map(fn ($t) => $t->value, $config->output->themes);
        $dir = $config->output->directory;
        $format = $config->output->format->value;

        $lines = [];
        $lines[] = '| Screenshot | '.implode(' | ', array_map('ucfirst', $themes)).' |';
        $lines[] = '|'.str_repeat('---|', count($themes) + 1);

        foreach ($grouped as $name => $themeResults) {
            $cols = [$this->humanize($name)];
            foreach ($themes as $theme) {
                if (isset($themeResults[$theme])) {
                    $cols[] = "![{$name}]({$dir}/{$theme}/{$name}.{$format})";
                } else {
                    $cols[] = '-';
                }
            }
            $lines[] = '| '.implode(' | ', $cols).' |';
        }

        return implode("\n", $lines);
    }

    protected function generateGallery(ScreentestConfig $config, array $results): string
    {
        $grouped = $this->groupByName($results);
        $dir = $config->output->directory;
        $format = $config->output->format->value;

        $lines = [];

        foreach ($grouped as $name => $themeResults) {
            $lines[] = "### {$this->humanize($name)}";
            $lines[] = '';

            foreach ($themeResults as $theme => $result) {
                $lines[] = '**'.ucfirst($theme).'**';
                $lines[] = '';
                $lines[] = "![{$name} - {$theme}]({$dir}/{$theme}/{$name}.{$format})";
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    protected function replaceSection(string $content, string $marker, string $section): string
    {
        $pattern = '/'.preg_quote($marker, '/').'.*?'.preg_quote($marker, '/').'/s';

        $replacement = $marker."\n".$section."\n".$marker;

        return preg_replace($pattern, $replacement, $content, 1);
    }

    /** @return array<string, array<string, CaptureResult>> */
    protected function groupByName(array $results): array
    {
        $grouped = [];

        foreach ($results as $result) {
            $grouped[$result->name][$result->theme] = $result;
        }

        return $grouped;
    }

    protected function humanize(string $name): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $name));
    }
}
