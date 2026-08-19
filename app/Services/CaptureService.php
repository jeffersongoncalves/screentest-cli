<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BeforeAction;
use App\DTOs\CaptureResult;
use App\DTOs\ScreenshotConfig;
use App\DTOs\ScreentestConfig;
use App\Exceptions\CaptureException;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

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
    public function capture(ScreentestConfig $config, string $projectPath, string $pluginPath, ?string $baseUrl = null): array
    {
        $this->installDependencies($projectPath);

        $this->generateCaptureScript($config, $projectPath, $baseUrl);

        $results = $this->executeCaptureScript($projectPath);

        return $this->copyToPlugin($results, $config, $projectPath, $pluginPath);
    }

    protected function installDependencies(string $projectPath): void
    {
        $packageJsonPath = $projectPath.'/package.json';
        $requiredDeps = [
            'puppeteer' => '^24.0.0',
            'sharp' => '^0.33.0',
        ];

        if (File::exists($packageJsonPath)) {
            // Add puppeteer/sharp to existing package.json if missing
            $packageJson = json_decode(File::get($packageJsonPath), true) ?? [];
            $changed = false;

            foreach ($requiredDeps as $pkg => $version) {
                if (! isset($packageJson['dependencies'][$pkg]) && ! isset($packageJson['devDependencies'][$pkg])) {
                    $packageJson['dependencies'][$pkg] = $version;
                    $changed = true;
                }
            }

            if ($changed) {
                File::put($packageJsonPath, json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            }
        } else {
            $stubPath = base_path('stubs/package.json.stub');

            if (File::exists($stubPath)) {
                File::copy($stubPath, $packageJsonPath);
            } else {
                File::put($packageJsonPath, json_encode([
                    'private' => true,
                    'dependencies' => $requiredDeps,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            }
        }

        $this->ensurePnpmBuildsAllowed($projectPath);

        // Puppeteer's own postinstall script has no retry/cleanup for a
        // corrupt cache entry (a folder left behind by an interrupted
        // download, with no executable inside it) — it just fails outright
        // and takes the whole `pnpm install` down with it. installChrome()
        // below already handles that case, so skip postinstall's download
        // entirely and let it be the only thing that ever fetches a browser.
        $puppeteerEnv = ['PUPPETEER_SKIP_DOWNLOAD' => 'true'];

        $installResult = $this->process->pnpm('install', $projectPath, timeout: 300, env: $puppeteerEnv);

        if (! $installResult->successful()) {
            throw new CaptureException(
                'pnpm install failed: '.$installResult->errorOutput().$installResult->output()
            );
        }

        $this->installChrome($projectPath);
    }

    /**
     * A chrome download interrupted by network hiccups or antivirus scanning
     * can leave a build folder on disk with no executable inside it. Puppeteer
     * then treats that folder as "already installed" and reports success
     * without ever placing the binary, so the exit code alone isn't trustworthy
     * here — the printed executable path is checked to exist on disk too.
     *
     * This shells out via a plain `node script.mjs` (like generateCaptureScript
     * does), not `pnpm exec puppeteer browsers install chrome` — on Windows,
     * PHP's Process loses the nested pnpm.cmd → node subprocess's stdout often
     * enough (empty output, exit 0, nothing installed) that the command isn't
     * trustworthy for this.
     */
    protected function installChrome(string $projectPath): void
    {
        $this->generateInstallChromeScript($projectPath);

        $result = $this->runInstallChromeScript($projectPath);

        if (! $this->chromeExecutableExists($result)) {
            $output = $result->output().$result->errorOutput();

            if (preg_match('#chrome[\\\\/]win64-([0-9.]+)#', $output, $matches)) {
                $cacheDir = getenv('PUPPETEER_CACHE_DIR') ?: (getenv('USERPROFILE') ?: getenv('HOME')).'/.cache/puppeteer';
                File::deleteDirectory(str_replace('\\', '/', $cacheDir).'/chrome/win64-'.$matches[1]);
            }

            $result = $this->runInstallChromeScript($projectPath);
        }

        if (! $this->chromeExecutableExists($result)) {
            throw new CaptureException(
                'Chrome install failed: '.$result->errorOutput().$result->output()
            );
        }
    }

    protected function runInstallChromeScript(string $projectPath)
    {
        try {
            return $this->process->node('install-chrome.mjs', $projectPath, timeout: 300);
        } catch (ProcessTimedOutException) {
            throw new CaptureException(
                'Chrome install timed out after 300s — the download to storage.googleapis.com may be blocked or hanging on this network.'
            );
        }
    }

    protected function generateInstallChromeScript(string $projectPath): void
    {
        // `@puppeteer/browsers` and `puppeteer-core` aren't direct dependencies
        // of this project, so pnpm's strict node_modules would refuse to
        // resolve them if imported directly (ERR_MODULE_NOT_FOUND). Going
        // through puppeteer's own re-exported install script instead: `puppeteer`
        // *is* a direct dependency, and its internal imports resolve fine from
        // its own package folder regardless of how strict our own project is.
        $script = <<<'JS'
            import puppeteer from 'puppeteer';
            import { downloadBrowsers } from 'puppeteer/lib/esm/puppeteer/node/install.js';

            process.env.PUPPETEER_SKIP_CHROME_HEADLESS_SHELL_DOWNLOAD ??= 'true';

            await downloadBrowsers();

            console.log(puppeteer.executablePath());
            JS;

        File::put($projectPath.'/install-chrome.mjs', $script);
    }

    protected function chromeExecutableExists($result): bool
    {
        return $result->successful() && File::isFile(trim($result->output()));
    }

    /**
     * pnpm 10+ blocks dependency postinstall/build scripts unless explicitly
     * allowlisted, otherwise `pnpm install` errors out before node_modules
     * is populated (ERR_PNPM_IGNORED_BUILDS).
     */
    protected function ensurePnpmBuildsAllowed(string $projectPath): void
    {
        $workspacePath = $projectPath.'/pnpm-workspace.yaml';

        if (File::exists($workspacePath)) {
            $contents = File::get($workspacePath);

            if (! str_contains($contents, 'allowBuilds:')) {
                File::append($workspacePath, "\nallowBuilds:\n  puppeteer: true\n  sharp: true\n");
            }

            return;
        }

        $stubPath = base_path('stubs/pnpm-workspace.yaml.stub');

        if (File::exists($stubPath)) {
            File::copy($stubPath, $workspacePath);
        } else {
            File::put($workspacePath, "allowBuilds:\n  puppeteer: true\n  sharp: true\n");
        }
    }

    protected function generateCaptureScript(ScreentestConfig $config, string $projectPath, ?string $baseUrl = null): string
    {
        if ($baseUrl === null) {
            $host = config('screentest.server.host', '127.0.0.1');
            $port = config('screentest.server.port', 8787);
            $baseUrl = "http://{$host}:{$port}";
        }

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
