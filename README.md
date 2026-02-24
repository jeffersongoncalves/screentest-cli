<div class="filament-hidden">

![Screentest CLI](https://raw.githubusercontent.com/jeffersongoncalves/screentest-cli/main/art/jeffersongoncalves-screentest-cli.png)

</div>

# Screentest CLI

CLI tool for automated screenshot generation of Filament plugins. Generates documentation screenshots in light and dark themes with zero manual effort.

## Features

- Reads a `screentest.json` config from your plugin
- Creates a temporary Filament project via filakit
- Installs the plugin via path repository (symlink)
- Auto-generates seeds by analyzing Resources (static analysis)
- Captures screenshots with Puppeteer (light + dark themes)
- Saves to `{plugin}/screenshots/{theme}/{name}.png`
- Optionally updates README.md with screenshot references

## Installation

```bash
composer global require jeffersongoncalves/screentest-cli
```

## Quick Start

```bash
# Navigate to your Filament plugin directory
cd my-filament-plugin

# Initialize config (interactive)
screentest init

# Run the complete pipeline
screentest run
```

## Commands

| Command | Description |
|---------|-------------|
| `screentest init` | Analyze plugin and generate `screentest.json` |
| `screentest run` | Run the complete pipeline (setup → seed → capture → readme → cleanup) |
| `screentest setup` | Create temporary Filament project and install plugin |
| `screentest seed` | Generate and run seeds for the temporary project |
| `screentest capture` | Capture screenshots using Puppeteer |
| `screentest readme` | Update README.md with screenshot references |
| `screentest cleanup` | Remove temporary project |

## Configuration

The `screentest.json` file controls the entire process:

```json
{
  "plugin": {
    "name": "My Filament Plugin",
    "package": "vendor/my-plugin"
  },
  "filakit": {
    "kit": "filakitphp/basev5"
  },
  "install": {
    "extra_packages": [],
    "plugins": [
      {
        "class": "Vendor\\MyPlugin\\MyPluginPlugin",
        "panel": "admin"
      }
    ],
    "publish": [],
    "post_install_commands": ["migrate"]
  },
  "seed": {
    "auto_detect": true,
    "user": {
      "email": "admin@example.com",
      "password": "password",
      "name": "Admin User"
    },
    "models": []
  },
  "screenshots": [
    {
      "name": "resource-list",
      "url": "admin/resources",
      "selector": "body",
      "viewport": { "width": 1920, "height": 1080, "deviceScaleFactor": 3 },
      "before": [
        { "action": "wait", "delay": 500 }
      ]
    }
  ],
  "output": {
    "directory": "screenshots",
    "themes": ["light", "dark"],
    "format": "png"
  },
  "readme": {
    "update": true,
    "section_marker": "<!-- SCREENSHOTS -->",
    "template": "table"
  }
}
```

## Requirements

- PHP 8.2+
- Node.js (for Puppeteer)
- pnpm
- Composer
- [Filakit CLI](https://github.com/jeffersongoncalves/filakit-cli) (installed globally)

## How It Works

1. **Init** - Analyzes your plugin's `composer.json` and Resources to generate a config
2. **Setup** - Creates a temporary Filament project using filakit, installs your plugin via symlink
3. **Seed** - Auto-detects Filament Resources, maps fields to Faker methods, generates factories and seeders
4. **Capture** - Launches Puppeteer, logs into Filament, navigates to each URL, captures screenshots in light and dark themes
5. **Readme** - Updates your README.md between `<!-- SCREENSHOTS -->` markers with a table or gallery

## Auto-Seed Field Mapping

| Filament Component | Faker Method |
|---|---|
| `TextInput('name')` | `$faker->name()` |
| `TextInput('email')` | `$faker->safeEmail()` |
| `TextInput('title')` | `$faker->sentence(4)` |
| `TextInput()->numeric()` | `$faker->numberBetween(0, 100)` |
| `Textarea` | `$faker->paragraph()` |
| `RichEditor` | `$faker->paragraph()` (wrapped in HTML) |
| `Toggle` / `Checkbox` | `$faker->boolean()` |
| `Select('..._id')` | FK to related model |
| `Select->options([...])` | Random from options |
| `DatePicker` | `$faker->date()` |
| `DateTimePicker` | `$faker->dateTime()` |
| `ColorPicker` | `$faker->hexColor()` |
| `FileUpload` | Skipped (null) |

## License

Screentest CLI is open-source software licensed under the [MIT license](LICENSE).
