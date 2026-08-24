# Changelog

All notable changes to this project will be documented in this file.

## [1.0.32] - 2026-08-24

### Bug Fixes

- Recognize singular ->hasMigration() DSL call, not just plural

## [1.0.31] - 2026-08-24

### Bug Fixes

- Resolve enum-backed Select options and transitive dependency scanning

## [1.0.30] - 2026-08-23

### Bug Fixes

- **capture:** Apply per-screenshot viewport and support fullPage capture

### CI/CD

- **release:** Generate CHANGELOG.md and release notes with git-cliff

## [1.0.29] - 2026-08-22

### Bug Fixes

- **capture:** Extract Docker build context from phar:// to a real path

## [1.0.28] - 2026-08-22

### Bug Fixes

- **init:** Require a literal bool/null default for a config() gate candidate

## [1.0.27] - 2026-08-22

### Bug Fixes

- **init:** Correlate config() gates to their env() default, detect spatie hasMigrations()

## [1.0.26] - 2026-08-22

### Documentation

- Document --deps flag for init

### Features

- **init:** Follow publishes()/env() calls into opted-in Composer deps

## [1.0.25] - 2026-08-22

### Bug Fixes

- **init:** Skip interactive prompts when not running in a tty

## [1.0.24] - 2026-08-19

### Features

- **init:** Detect standalone custom Filament pages

## [1.0.23] - 2026-08-19

### Bug Fixes

- **seed:** Explicit seed.models config now wins over auto-detect

## [1.0.22] - 2026-08-19

### Bug Fixes

- **seed:** Fail loudly when the seeder run throws

## [1.0.21] - 2026-08-19

### Bug Fixes

- **capture:** Collapse double slash in screenshot navigation URL

## [1.0.20] - 2026-08-19

### CI/CD

- Remove non-functional PHPStan workflow

## [1.0.19] - 2026-08-19

### Dependencies

- **deps:** Bump sharp

### Features

- **install:** Support install.env in screentest.json

## [1.0.18] - 2026-08-19

### Bug Fixes

- **capture:** Resolve Herd's named host into the capture container

## [1.0.17] - 2026-08-19

### Features

- **capture:** Run screenshot capture inside a Docker container

## [1.0.16] - 2026-08-19

### Bug Fixes

- **capture:** Resolve chrome installer deps via puppeteer re-export

## [1.0.15] - 2026-08-19

### Bug Fixes

- **capture:** Skip puppeteer postinstall download entirely

## [1.0.14] - 2026-08-19

### Bug Fixes

- **capture:** Skip unused chrome-headless-shell download

## [1.0.13] - 2026-08-19

### Bug Fixes

- **capture:** Allow pnpm build scripts and verify chrome install

## [1.0.12] - 2026-08-18

### Bug Fixes

- **deps:** Update guzzlehttp/guzzle to patch security advisories
- **analyzer:** Follow delegated Form::configure() calls to detect fields

### CI/CD

- Pin actions to commit SHA, add dependabot cooldown/composer, trim dist archive

### Miscellaneous Tasks

- Bump guzzlehttp/guzzle and guzzlehttp/psr7 for security advisories

### Other

- Bump shivammathur/setup-php

Bumps [shivammathur/setup-php](https://github.com/shivammathur/setup-php) from b604ade2a87db23f8871b7182e69ec5e75effb45 to f3e473d116dcccaddc5834248c87452386958240.
- [Release notes](https://github.com/shivammathur/setup-php/releases)
- [Commits](https://github.com/shivammathur/setup-php/compare/b604ade2a87db23f8871b7182e69ec5e75effb45...f3e473d116dcccaddc5834248c87452386958240)

---
updated-dependencies:
- dependency-name: shivammathur/setup-php
  dependency-version: f3e473d116dcccaddc5834248c87452386958240
  dependency-type: direct:production
...

Signed-off-by: dependabot[bot] <support@github.com>

## [1.0.11] - 2026-07-24

### CI/CD

- Replace split build/changelog/publish-phar workflows with a single release job

### Miscellaneous Tasks

- Add missing CHANGELOG.md

## [1.0.10] - 2026-06-23

### Features

- Adicionar comando self-update

### Refactor

- Consume shared laravel-zero-* packages

## [1.0.9] - 2026-06-06

### Bug Fixes

- Use PHP_BINARY instead of .bat wrapper for proc_open server start
- Ensure .env exists and APP_KEY is generated after project creation
- Add tests/Feature directory to fix CI parallel test runner

### CI/CD

- **build:** Serialize builds with a concurrency group to avoid ref-lock race
- **release:** Use version.txt as the single source of truth for the version

### Features

- Integrate Laravel Herd for serving and fix capture pipeline

### Miscellaneous Tasks

- Refresh portfolio banner
- Bump version to v1.0.9

### Other

- Standardize GitHub workflows: update actions, add missing workflows (phpstan, pint, dependabot)
- Standardize .gitignore: add .claude/settings.local.json, .phpunit.cache, .env
- Chain builds after Update Changelog + fix release-tagged rebuild

On the release path, three workflows fan out in parallel: publish-phar,
Update Changelog, and builds. Update Changelog force-pushes CHANGELOG
and version.txt, which raced with builds and caused non-fast-forward
rejections. Worse, the tag created by the release stayed on the commit
that existed before the PHAR was rebuilt, so `composer require` would
pull a PHAR with the previous version baked in.

This rewires build.yml to:

- Run via workflow_run after Update Changelog completes successfully,
  eliminating the race. Regular push on main still triggers.
- Pin ref and commit branch to main on workflow_run invocations
  (github.event.workflow_run.head_branch resolves to the tag name for
  release events and would land the commit on a detached HEAD / fail
  to push).
- Resolve the build version from workflow_run.head_branch when running
  under workflow_run. `git describe --tags --abbrev=0` is unreliable
  once the pre-release tag and current release tag share a commit.
- After the rebuild commit lands, move the release tag to that commit
  so Packagist (and direct git installs) serve the PHAR whose embedded
  version matches the tag.

Validated end-to-end in the git-worktree-cli sibling repo.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
- Delete .github/workflows/dependabot-auto-merge.yml

## [1.0.8] - 2026-02-25

### Other

- Fix server startup: use proc_open with cwd, kill by port fallback, capture stderr for debugging

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>

## [1.0.7] - 2026-02-25

### Other

- Fix dev server startup on Windows

- Use proc_open instead of Process::start for reliable background server
- Use fsockopen instead of Http::get for server readiness check
- Handle proc_terminate for server shutdown
- Remove Http facade dependency

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>

## [1.0.6] - 2026-02-25

### Other

- Fix temp directory cleanup on Windows

File::deleteDirectory() fails with symlinks on Windows.
Use system rmdir /s /q on Windows and rm -rf on Unix with fallback.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>

## [1.0.5] - 2026-02-25

### Other

- Fix plugin registration breaking PanelProvider

The regex was inserting ->plugin() inside discoverResources() arguments
because nested parentheses confused the pattern. Now uses string position
anchoring on ->middleware() or ->authMiddleware() as safe insertion points.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>

## [1.0.4] - 2026-02-25

### Other

- Fix binary detection: auto-detect Herd paths on Windows

- Composer is called directly (not as php argument)
- Add composerOrFail() method for operations that must succeed
- Auto-detect Laravel Herd php.bat and composer.bat on Windows
- Fall back to global composer path if Herd not found

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>

## [1.0.3] - 2026-02-24

### Other

- Use composer create-project instead of laravel new --using

The laravel installer's --using flag delegates to composer create-project
but fails with mkdir() on Windows. Using composer create-project directly
works reliably across platforms.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>

## [1.0.2] - 2026-02-24

### Other

- Replace filakit CLI with laravel new --using for project setup

Use native Laravel installer with --using flag instead of filakit CLI binary.
Fixes path separator issues on Windows and removes filakit CLI dependency.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>

## [1.0.1] - 2026-02-24

### Other

- Fix base kit references to filakitphp org

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>

## [1.0.0] - 2026-02-24

### Other

- Initial implementation of Screentest CLI

Laravel Zero CLI tool for automated screenshot generation of Filament plugins.

- 7 commands: init, run, setup, seed, capture, readme, cleanup
- Plugin static analysis (regex-based) to detect Resources and fields
- Auto-seed generation mapping Filament fields to Faker methods
- Puppeteer integration for light/dark theme screenshots
- README.md auto-update with screenshot table/gallery
- 16 DTOs, 4 Enums, 4 Exceptions, 7 Services
- GitHub Actions workflows (build, publish-phar, tests, changelog)
- Box PHAR build configuration

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
- Apply Pint code style fixes

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>


