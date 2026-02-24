<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BeforeAction;
use App\DTOs\CaptureResult;
use App\DTOs\ScreenshotConfig;
use App\DTOs\ScreentestConfig;
use App\Exceptions\CaptureException;
use Illuminate\Support\Facades\File;

class CaptureService
{
    public function __construct(
        protected ProcessService $process,
    ) {}

    /**
     * Capture screenshots using Puppeteer.
     *
     * @return array<CaptureResult>
     */
    public function capture(ScreentestConfig $config, string $projectPath, string $pluginPath): array
    {
        $this->installDependencies($projectPath);

        $this->generateCaptureScript($config, $projectPath);

        $results = $this->executeCaptureScript($projectPath);

        return $this->copyToPlugin($results, $config, $projectPath, $pluginPath);
    }

    protected function installDependencies(string $projectPath): void
    {
        $packageJsonPath = $projectPath.'/package.json';

        if (! File::exists($packageJsonPath)) {
            $stubPath = base_path('stubs/package.json.stub');

            if (File::exists($stubPath)) {
                File::copy($stubPath, $packageJsonPath);
            } else {
                File::put($packageJsonPath, json_encode([
                    'private' => true,
                    'dependencies' => [
                        'puppeteer' => '^24.0.0',
                        'sharp' => '^0.33.0',
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            }
        }

        $this->process->pnpm('install', $projectPath, timeout: 300);
    }

    protected function generateCaptureScript(ScreentestConfig $config, string $projectPath): string
    {
        $host = config('screentest.server.host', '127.0.0.1');
        $port = config('screentest.server.port', 8787);
        $baseUrl = "http://{$host}:{$port}";

        $outputDir = $projectPath.'/screenshots';
        $navigationTimeout = config('screentest.capture.navigation_timeout', 30000);

        $configData = [
            'baseUrl' => $baseUrl,
            'user' => [
                'email' => $config->seed->user->email,
                'password' => $config->seed->user->password,
            ],
            'screenshots' => array_map(fn (ScreenshotConfig $screenshot) => [
                'name' => $screenshot->name,
                'url' => $screenshot->url,
                'selector' => $screenshot->selector,
                'before' => array_map(fn (BeforeAction $action) => array_filter([
                    'action' => $action->action->value,
                    'selector' => $action->selector,
                    'value' => $action->value,
                    'delay' => $action->delay,
                ], fn ($v) => $v !== null), $screenshot->before),
                'crop' => $screenshot->crop ? [
                    'x' => $screenshot->crop->x,
                    'y' => $screenshot->crop->y,
                    'width' => $screenshot->crop->width,
                    'height' => $screenshot->crop->height,
                ] : null,
                'viewport' => $screenshot->viewport ? [
                    'width' => $screenshot->viewport->width,
                    'height' => $screenshot->viewport->height,
                    'deviceScaleFactor' => $screenshot->viewport->deviceScaleFactor,
                ] : null,
            ], $config->screenshots),
            'themes' => array_map(fn ($theme) => $theme->value, $config->output->themes),
            'viewport' => [
                'width' => 1920,
                'height' => 1080,
                'deviceScaleFactor' => 3,
            ],
            'format' => $config->output->format->value,
            'outputDir' => str_replace('\\', '/', $outputDir),
            'navigationTimeout' => $navigationTimeout,
        ];

        $configJson = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $stubPath = base_path('stubs/capture.mjs.stub');

        if (File::exists($stubPath)) {
            $script = File::get($stubPath);
            $script = str_replace('{{CONFIG_JSON}}', $configJson, $script);
        } else {
            $script = $this->buildCaptureScript($configJson);
        }

        $scriptPath = $projectPath.'/capture.mjs';
        File::put($scriptPath, $script);

        return $scriptPath;
    }

    /**
     * @return array<CaptureResult>
     */
    protected function executeCaptureScript(string $projectPath): array
    {
        $result = $this->process->node('capture.mjs', $projectPath, timeout: 300);

        if (! $result->successful()) {
            throw new CaptureException(
                'Capture script failed: '.$result->errorOutput()
            );
        }

        $stdout = $result->output();
        $results = [];

        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);

            if (! is_array($data)) {
                continue;
            }

            if (($data['type'] ?? null) === 'progress' && ($data['status'] ?? null) === 'done') {
                $results[] = new CaptureResult(
                    name: $data['name'],
                    theme: $data['theme'],
                    path: $data['path'] ?? '',
                    success: true,
                );
            }

            if (($data['type'] ?? null) === 'progress' && ($data['status'] ?? null) === 'error') {
                $results[] = new CaptureResult(
                    name: $data['name'],
                    theme: $data['theme'],
                    path: '',
                    success: false,
                    error: $data['error'] ?? 'Unknown error',
                );
            }
        }

        return $results;
    }

    /**
     * @param  array<CaptureResult>  $results
     * @return array<CaptureResult>
     */
    protected function copyToPlugin(array $results, ScreentestConfig $config, string $projectPath, string $pluginPath): array
    {
        $outputDirectory = $config->output->directory;
        $format = $config->output->format->value;
        $updated = [];

        foreach ($results as $result) {
            if (! $result->success) {
                $updated[] = $result;

                continue;
            }

            $sourcePath = $projectPath.'/screenshots/'.$result->theme.'/'.$result->name.'.'.$format;
            $targetDir = $pluginPath.'/'.$outputDirectory.'/'.$result->theme;
            $targetPath = $targetDir.'/'.$result->name.'.'.$format;

            if (! File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }

            if (File::exists($sourcePath)) {
                File::copy($sourcePath, $targetPath);
            }

            $relativePath = $outputDirectory.'/'.$result->theme.'/'.$result->name.'.'.$format;

            $updated[] = new CaptureResult(
                name: $result->name,
                theme: $result->theme,
                path: $relativePath,
                success: $result->success,
                error: $result->error,
            );
        }

        return $updated;
    }

    protected function buildCaptureScript(string $configJson): string
    {
        return <<<JS
import puppeteer from 'puppeteer';
import sharp from 'sharp';
import fs from 'fs';
import path from 'path';

const config = {$configJson};

function log(data) {
  console.log(JSON.stringify(data));
}

async function main() {
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });

  try {
    for (const theme of config.themes) {
      const page = await browser.newPage();

      await page.setViewport({
        width: config.viewport.width,
        height: config.viewport.height,
        deviceScaleFactor: config.viewport.deviceScaleFactor,
      });

      await page.emulateMediaFeatures([
        { name: 'prefers-color-scheme', value: theme },
      ]);

      // Login
      await page.goto(`\${config.baseUrl}/admin/login`, { waitUntil: 'networkidle0' });
      await page.type('[name="data.email"]', config.user.email);
      await page.type('[name="data.password"]', config.user.password);
      await page.click('button[type="submit"]');
      await page.waitForNavigation({ waitUntil: 'networkidle0' });

      for (const screenshot of config.screenshots) {
        try {
          await page.goto(`\${config.baseUrl}/\${screenshot.url}`, { waitUntil: 'networkidle0', timeout: config.navigationTimeout });

          // Execute before actions
          for (const action of (screenshot.before || [])) {
            switch (action.action) {
              case 'click':
                await page.click(action.selector);
                break;
              case 'hover':
                await page.hover(action.selector);
                break;
              case 'wait':
                await new Promise(r => setTimeout(r, action.delay || 500));
                break;
              case 'type':
                await page.type(action.selector, action.value);
                break;
              case 'select':
                await page.select(action.selector, action.value);
                break;
              case 'scroll':
                await page.evaluate((sel) => document.querySelector(sel)?.scrollIntoView(), action.selector);
                break;
            }
          }

          const outputDir = path.join(config.outputDir, theme);
          fs.mkdirSync(outputDir, { recursive: true });

          const filePath = path.join(outputDir, `\${screenshot.name}.\${config.format}`);

          let element = screenshot.selector === 'body' ? page : await page.$(screenshot.selector);
          if (!element) element = page;

          const buffer = await element.screenshot({ type: config.format === 'jpg' ? 'jpeg' : 'png' });

          if (screenshot.crop) {
            const cropped = await sharp(buffer)
              .extract({ left: screenshot.crop.x, top: screenshot.crop.y, width: screenshot.crop.width, height: screenshot.crop.height })
              .toBuffer();
            fs.writeFileSync(filePath, cropped);
          } else if (config.format === 'webp') {
            const converted = await sharp(buffer).webp().toBuffer();
            fs.writeFileSync(filePath, converted);
          } else {
            fs.writeFileSync(filePath, buffer);
          }

          log({ type: 'progress', name: screenshot.name, theme, status: 'done', path: filePath });
        } catch (err) {
          log({ type: 'progress', name: screenshot.name, theme, status: 'error', error: err.message });
        }
      }

      await page.close();
    }
  } finally {
    await browser.close();
  }

  log({ type: 'complete' });
}

main().catch(err => {
  console.error(err);
  process.exit(1);
});
JS;
    }
}
