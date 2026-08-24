# AGENTS.md

Instructions for AI coding agents working in this repository, and for agents writing/fixing a `screentest.json` for a target Filament plugin.

## Project

`screentest-cli` (binary `screentest`) — generates screenshots for a Filament plugin by spinning up a throwaway Laravel+Filament project (via [filakit](https://github.com/filakitphp)), installing the plugin into it, seeding data, and driving a headless Chrome (in Docker — see `stubs/docker/`) through each configured URL. Pipeline: `create` → `install` → `publish` → `post-install` → `build assets` → `seed` → `serve` → `capture` → `readme`.

## Setup

```bash
composer install
```

## Commands

```bash
php vendor/bin/pest --parallel   # Pest test suite
php vendor/bin/pint --test       # Laravel Pint (check only)
php vendor/bin/pint              # Laravel Pint (fix)
```

No PHPStan in this project (unlike bb-cli/jira-cli/installer in the monorepo).

## Writing a plugin's `screentest.json`

Copy `stubs/screentest.json.stub` into the target plugin's repo root and fill in every field below. Ordering matters — install/publish/post-install run in this order, before seeding, before the browser ever loads a page.

### Running `init` non-interactively (agents, CI)

`screentest init` prompts (plugin name, package name, screenshot selection, README update) via Laravel Prompts. Under an agent harness with no tty, Symfony's `$input->isInteractive()` is `false`, so `init` automatically skips every prompt and falls back to flags/defaults instead of hanging:

```bash
screentest init --path=. \
  --name="My Plugin" \
  --package=vendor/my-plugin \
  --screenshots=all \
  --no-readme
```

- `--name` / `--package` — default to the analyzer's guess when omitted.
- `--screenshots` — comma-separated keys from the detected resources/pages (e.g. `thing-list,thing-edit`), or `all` (default when omitted), or `""` for none.
- `--no-readme` — skip the README update (default: update).
- `--deps` — comma-separated Composer package names (e.g. `vendor/laravel-short-url`) to also scan. For plugins that are thin wrappers around a standalone "kit" package, the interesting `publishes()`/`env()` calls often live one level down, in the wrapped package, not in the plugin's own `src/` — see below.

```jsonc
{
  "plugin": { "name": "...", "package": "vendor/package" },
  "filakit": { "kit": "filakitphp/basev5" },
  "install": {
    "extra_packages": [],           // composer packages beyond the plugin itself
    "plugins": [{ "class": "Vendor\\Plugin\\Class", "panel": "admin" }],
    "publish": [],                  // vendor:publish --tag=<...> — see below, this is the #1 cause of blank/404 screenshots
    "post_install_commands": ["migrate"],
    "env": {}                       // KEY=value pairs written into the temp project's .env — see below
  },
  "seed": {
    "auto_detect": true,
    "user": { "email": "admin@example.com", "password": "password", "name": "Admin User" },
    "models": [{ "model": "Vendor\\Plugin\\Models\\Thing", "count": 10 }]
  },
  "screenshots": [{ "name": "thing-list", "url": "/admin/things" }],
  "output": { "directory": "screenshots", "themes": ["light", "dark"], "format": "png" },
  "readme": { "update": true, "section_marker": "<!-- SCREENSHOTS -->", "template": "table" }
}
```

### `install.publish` — package migrations shipped as `.php.stub`

Many packages (anything using `spatie/laravel-package-tools`'s `->hasMigrations()`) ship migrations as `database/migrations/*.php.stub`, **not** `.php`. Laravel's migrator only picks up `.php` files, so `artisan migrate` silently runs zero of them unless they're published first — `hasMigrations()` alone does **not** auto-load them; the package's `runsMigrations` flag defaults to `false` in `spatie/laravel-package-tools` and this pattern deliberately leaves it off so the host app can `vendor:publish` and edit the migrations before running them.

**Symptom:** every seeded/listing screenshot for that package's resources comes back as an identical, content-less error page (a Laravel "Internal Server Error" / `SQLSTATE... no such table: X` — or, if debug is off, a generic error page indistinguishable across every screenshot because every page hits the same missing-table exception).

**Finding the right tag:** `spatie/laravel-package-tools` names the migrations publish tag `"{$package->shortName()}-migrations"`, where `shortName()` is the package's `->name('...')` value with a leading `laravel-` stripped (`Str::after($name, 'laravel-')`). So package name `laravel-short-url` → tag `short-url-migrations`. Don't guess — confirm once per plugin:

```bash
cd <temp-or-scratch-project>
php artisan vendor:publish --provider="Vendor\Plugin\PluginServiceProvider"   # publishes everything, prints exact tag names used
# or, once you have a guess:
php artisan vendor:publish --tag=<guess> --force   # "No publishable resources for tag [...]" means wrong guess
```

Add the confirmed tag(s) to `install.publish` in `screentest.json`. `post_install_commands: ["migrate"]` only works *after* this — publish always runs before post-install commands in the pipeline, so ordering in the config file doesn't matter, only that the tag is present in `publish` at all.

**If the plugin is a thin wrapper around another package** (a common `jeffersongoncalves/filament-*` "kit" pattern — a Filament plugin around a standalone service package), the plugin's own `src/` has none of this: no `publishes()`, no `env()` calls. Pass the wrapped package to `init --deps=` and the analyzer scans `vendor/<package>/` too, auto-populating `install.publish` (from `publishes()`/`publishesMigrations()` tags) and `install.env` (from `env('FLAG', false)` reads that default to disabled) — see the `init` section above. This also walks that package's own `composer.json` `require` entries (up to 3 levels), so a common dependency like `spatie/laravel-medialibrary` pulled in transitively via `filament/spatie-laravel-media-library-plugin` gets picked up too, without needing to pass it to `--deps` explicitly.

**Safety net:** `capture`/`run` now flag a captured screenshot with a warning (printed after the run, doesn't fail the pipeline) when the page's HTTP status is 4xx/5xx or its HTML matches a Laravel debug-mode error page (Whoops/Ignition) — so a missed publish tag, or a URL pointed at an auth/signature-gated route, surfaces in the CLI output instead of only being visible by opening the PNG.

### `install.env` — feature flags gating resource registration

Some plugins register Filament resources conditionally, e.g.:

```php
->resources([
    OptionalResource::class => (bool) config('package.feature.enabled', false),
])
```

If that env-backed config defaults to `false`, the resource's routes never register — screenshots for exactly that resource (and only that one) 404, while everything else works. This is different from the migrations symptom: it's scoped to one resource, not everything, and it's a real 404 (route doesn't exist) rather than a 500 (route exists, query fails).

**Finding the flags:** grep the plugin's `getResources()`/panel-registration method for `config('...')` calls gating a resource, then check the referenced package's own `config/*.php` for the matching `env('SOME_ENV_KEY', false)` default. Set them in `screentest.json`:

```json
"install": {
  "env": {
    "SHORT_URL_API_ENABLED": "true",
    "SHORT_URL_DOMAINS_ENABLED": "true",
    "SHORT_URL_BIO_ENABLED": "true"
  }
}
```

These get written into the temp project's `.env` before install/seed/serve — see `ProjectService::ensureEnvironment()`.

### Screenshot URLs needing an existing record (`/1/edit` etc.)

`seed.models` must create at least one row of the right model *before* any `.../1/edit`-style URL in `screenshots` is captured — auto-detected seeding (`seed.auto_detect: true`) usually covers this, but if a model has required relations the factory doesn't fill in, seeding can throw and silently leave the table empty. Check `Generating and running seeds: ✔` isn't hiding a partial failure by spot-checking row counts if an edit screenshot looks wrong.

### Screenshot URLs behind Laravel's `signed` middleware

A literal `url` can't carry a valid signature — it depends on the temp project's own `APP_KEY`, which this CLI never sees. Use `route`/`routeParams`/`signed` instead of `url`:

```json
{ "name": "confirm", "route": "newsletter.confirm", "routeParams": { "emailGroupMember": 1 }, "signed": true }
```

Before capture, `CaptureService::resolveRouteUrls()` drops a throwaway `app/Console/Commands/ScreentestResolveUrls.php` into the temp project and runs `php artisan screentest:resolve-urls` there — it's the only place `URL::signedRoute()` can produce a signature that will actually validate. `signed: false` (or omitted) still resolves the route by name via the plain `route()` helper, useful for a named route whose path you don't want to hardcode. A route that fails to resolve (bad param, unknown route name) shows up as a normal failed `CaptureResult` per theme — it never reaches the browser with an empty URL.

## Troubleshooting checklist (symptom → cause)

| Screenshot looks like... | Likely cause |
|---|---|
| Every screenshot identical, generic error/500 page | Package migrations not published — see `install.publish` above |
| Only *some* resources 404, others fine | Resource gated behind a config/env flag — see `install.env` above |
| `ERR_CONNECTION_REFUSED` at `http://<project>.test/...` | Docker capture container can't resolve the Herd hostname — should be handled automatically by `CaptureService::resolveDockerBaseUrl()` (`docker run --add-host <host>:host-gateway`); if it recurs, check that a `baseUrl` isn't being passed in some other way that bypasses that method |
| `/1/edit` page shows a 404/missing-record error | That model's seed didn't create record `id=1` — check `seed.models` and seeding output |
| `Could not find Chrome` / pnpm/chrome install errors | Should not happen — capture runs entirely inside `stubs/docker/`'s Chrome-baked image. If it does, `ensureDockerImage()` failed to build; check `docker build -t screentest-cli-capture:1 stubs/docker` manually |

## Conventions

- Commit messages: English, Conventional Commits, explain *why* over *what* (except inside `sami-sistemas` sub-repos elsewhere in the monorepo, which use pt-BR — not applicable here).
- Releases: GitHub Actions `workflow_dispatch` on `.github/workflows/release.yml` — auto-bumps patch version, builds the PHAR, tags, and publishes a release. Never hand-edit `CHANGELOG.md` — it's populated by the release workflow.
