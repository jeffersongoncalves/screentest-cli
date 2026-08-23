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
    protected const CONTAINER_OUTPUT_DIR = '/app/screenshots';

    protected const CONTAINER_SCRIPT_PATH = '/app/capture.mjs';

    public function __construct(
        protected ProcessService $process,
    ) {}

    /**
     * Capture screenshots using Puppeteer, run inside a Docker container that
     * already has Chrome, puppeteer and sharp baked in. Installing those on
     * the host proved unreliable across machines (pnpm build-script
     * allowlisting, antivirus interference, corporate firewalls hanging the
     * chrome-for-testing download) — the container sidesteps all of it.
     *
     * @return array<CaptureResult>
     */
    public function capture(ScreentestConfig $config, string $projectPath, string $pluginPath, ?string $baseUrl = null): array
    {
        $this->ensureDockerImage();

        [$resolvedBaseUrl, $extraHost] = $this->resolveDockerBaseUrl($baseUrl);

        $this->generateCaptureScript($config, $projectPath, $resolvedBaseUrl);

        $results = $this->executeCaptureScript($projectPath, $extraHost);

        return $this->copyToPlugin($results, $config, $projectPath, $pluginPath);
    }

    /**
     * The capture container can't reach the host machine via 127.0.0.1 or
     * localhost — that's the container itself — so those get swapped for
     * Docker Desktop's host-access hostname. A named host (Herd's *.test
     * domains) is virtual-host routed by nginx via the Host header though,
     * so the URL must stay untouched or Herd serves the wrong site — instead
     * the container's DNS is taught to resolve that exact name to the host
     * via `docker run --add-host`.
     *
     * @return array{0: string, 1: ?string} [baseUrl, extraHost for --add-host]
     */
    protected function resolveDockerBaseUrl(?string $baseUrl): array
    {
        if ($baseUrl === null) {
            $host = config('screentest.server.host', '127.0.0.1');
            $port = config('screentest.server.port', 8787);
            $baseUrl = "http://{$host}:{$port}";
        }

        $host = parse_url($baseUrl, PHP_URL_HOST) ?? '127.0.0.1';

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return [str_replace($host, 'host.docker.internal', $baseUrl), null];
        }

        return [$baseUrl, $host];
    }

    protected function ensureDockerImage(): void
    {
        $image = config('screentest.docker_image', 'screentest-cli-capture:1');

        $inspect = $this->process->docker('image inspect '.$image, timeout: 30);

        if ($inspect->successful()) {
            return;
        }

        $context = $this->dockerBuildContextPath();

        $build = $this->process->docker('build -t '.$image.' "'.$context.'"', timeout: 600);

        if (! $build->successful()) {
            throw new CaptureException(
                'Docker image build failed: '.$build->errorOutput().$build->output()
            );
        }
    }

    /**
     * `base_path('stubs/docker')` resolves inside the phar (a `phar://...` stream-wrapped
     * path) when this binary is running as a compiled `.phar` — the normal Composer global
     * install. PHP's own file functions understand that scheme transparently, but Docker's
     * CLI/daemon doesn't; handed a `phar://` path it just reports "path not found". Extract
     * `stubs/docker` to a real, cached temp directory in that case and use that as the build
     * context instead; a source checkout (`base_path()` already a real path) is used as-is.
     */
    protected function dockerBuildContextPath(): string
    {
        $pharPath = \Phar::running(false);

        if ($pharPath === '') {
            return base_path('stubs/docker');
        }

        $target = sys_get_temp_dir().'/screentest-docker-build-'.md5($pharPath.filemtime($pharPath));

        if (! File::isDirectory($target.'/stubs/docker')) {
            (new \Phar($pharPath))->extractTo($target, 'stubs/docker', true);
        }

        return $target.'/stubs/docker';
    }

    protected function generateCaptureScript(ScreentestConfig $config, string $projectPath, string $baseUrl): string
    {
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
                'fullPage' => $screenshot->fullPage,
            ], $config->screenshots),
            'themes' => array_map(fn ($theme) => $theme->value, $config->output->themes),
            'viewport' => [
                'width' => 1920,
                'height' => 1080,
                'deviceScaleFactor' => 3,
            ],
            'format' => $config->output->format->value,
            'outputDir' => self::CONTAINER_OUTPUT_DIR,
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
    protected function executeCaptureScript(string $projectPath, ?string $extraHost = null): array
    {
        $scriptPath = $projectPath.'/capture.mjs';
        $screenshotsDir = $projectPath.'/screenshots';

        File::ensureDirectoryExists($screenshotsDir);

        $image = config('screentest.docker_image', 'screentest-cli-capture:1');

        $addHostFlag = $extraHost !== null ? ' --add-host '.$extraHost.':host-gateway' : '';

        $command = sprintf(
            'run --rm%s -v "%s:%s" -v "%s:%s" %s',
            $addHostFlag,
            $scriptPath,
            self::CONTAINER_SCRIPT_PATH,
            $screenshotsDir,
            self::CONTAINER_OUTPUT_DIR,
            $image,
        );

        $result = $this->process->docker($command, timeout: 300);

        if (! $result->successful()) {
            throw new CaptureException(
                'Capture script failed: '.$result->errorOutput().$result->output()
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

      // Login (session cookies are shared across pages in the same browser,
      // so subsequent themes may already be authenticated and redirect to dashboard)
      await page.goto(`\${config.baseUrl}/admin/login`, { waitUntil: 'networkidle0' });

      const needsLogin = !page.url().includes('/admin/login') ? false
        : !!(await page.\$('input[type="email"]') || await page.\$('[name="data.email"]'));

      if (needsLogin) {
        const emailSelector = (await page.\$('[name="data.email"]'))
          ? '[name="data.email"]'
          : 'input[type="email"]';
        const passwordSelector = (await page.\$('[name="data.password"]'))
          ? '[name="data.password"]'
          : 'input[type="password"]';

        await page.type(emailSelector, config.user.email);
        await page.type(passwordSelector, config.user.password);
        await page.click('button[type="submit"]');
        await page.waitForNavigation({ waitUntil: 'networkidle0' });
      }

      for (const screenshot of config.screenshots) {
        try {
          const viewport = screenshot.viewport || config.viewport;
          await page.setViewport({
            width: viewport.width,
            height: viewport.height,
            deviceScaleFactor: viewport.deviceScaleFactor,
          });

          const targetUrl = `\${config.baseUrl}/\${screenshot.url}`.replace(/([^:]\/)\/+/g, '\$1');
          await page.goto(targetUrl, { waitUntil: 'networkidle0', timeout: config.navigationTimeout });

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

          const buffer = await element.screenshot({
            type: config.format === 'jpg' ? 'jpeg' : 'png',
            fullPage: element === page ? !!screenshot.fullPage : undefined,
          });

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
