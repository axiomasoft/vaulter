# Vaulter

Files, folders and collaborative documents for any Laravel app — authorized by your own roles.

## Requirements

- PHP 8.4, Laravel 12 or 13
- PostgreSQL 15+ (primary), MySQL 8.0+, or SQLite for development
- Node 20+ for the `@vaulter/vue` client (Node 22+ for the optional collaboration hub)

## Install

<!-- snippet: shell -->

```bash
composer require axioma-studio/vaulter-drive
php artisan vaulter:install   # publishes config, asks to run the migrations
npm install @vaulter/vue
```

## Quick example

<!-- recipe: packages/drive/tests/Recipes/ReadmeRecipeTest.php#readme-quick-example -->

```php
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Facades\Vaulter;

// $project is any Eloquent model; $user is the signed-in user.
$drive = Vaulter::drive($project)->actingAs($user);

$reports = $drive->folders()->create('Reports');
$file = $drive->files()->upload(UploadSource::fromString('hello', 'hello.txt'), new UploadOptions(parent: $reports));
```

## Packages

| Package | Purpose |
| --- | --- |
| `axioma-studio/vaulter-core` | contracts, guards, shared kernel |
| `axioma-studio/vaulter-drive` | folders, files, uploads (tus/S3) |
| `axioma-studio/vaulter-documents` | editor, versions, comments, collaboration (opt-in) |
| `axioma-studio/vaulter-media` | content-addressed storage |
| `axioma-studio/vaulter-azgard` | AzGuard permission resolver |
| `@vaulter/vue` | TypeScript / Vue 3 client and components |
| `axioma-studio/vaulter-filament` | Filament admin (optional, Laravel 13) |

The optional collaboration hub lives in `hocuspocus-server/`.

## Documentation

- [Quick start](docs/getting-started/quick-start.md) · [Installation](docs/getting-started/installation.md)
- [Concepts](docs/concepts/architecture.md) · [Glossary](docs/concepts/glossary.md)
- [Guides](docs/guides/uploads.md) · [Extending](docs/extending/profiles.md)
- [Operations runbooks](docs/guides/operations.md) · [Security](docs/security/threat-model.md)
- [Contributing](CONTRIBUTING.md) · [Upgrading](UPGRADE.md)

## Monorepo layout

```
vaulter/
├── packages/
│   ├── core/                  # axioma-studio/vaulter-core — the shared kernel (Vaulter\)
│   ├── drive/                 # axioma-studio/vaulter-drive — folders/files, uploads, storage manager
│   ├── documents/             # axioma-studio/vaulter-documents — TipTap/Yjs editing, versions, comments
│   ├── media/                 # axioma-studio/vaulter-media — content-addressed (CAS) file/blob layer
│   ├── azgard/                # axioma-studio/vaulter-azgard — AzGuard PermissionResolver adapter
│   ├── vue/                   # @vaulter/vue — TypeScript Vue 3 client, composables & components
│   └── filament/              # axioma-studio/vaulter-filament — Filament 5 admin (Vaulter\Filament\)
├── hocuspocus-server/    # optional Node Hocuspocus collaboration hub (+ Docker)
├── docs/                 # VitePress developer documentation
└── (pint, phpstan, rector, pest, Makefile, CI…)
```

Integrations follow the AzGuard convention — each lives under `packages/` with its own namespace
(`Vaulter\Filament\`, …) and is tested in isolation so optional dependencies (Filament) never
constrain the core matrix.

## Development

<!-- snippet: shell -->

```bash
composer install && make ci      # Pint + PHPStan (level max) + Rector + Pest
npm install && npm test          # vue-tsc + Vitest
npm run docs:dev                 # VitePress
```

Every public class is strictly typed and covered by tests; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE) © Axioma Studio.
