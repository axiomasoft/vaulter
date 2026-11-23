# Vaulter

Files, folders and collaborative documents for any Laravel app — authorized by your own roles.

## Requirements

- PHP 8.4, Laravel 13
- PostgreSQL 18 (primary), MySQL 9.7 LTS, or SQLite for development
- Node 26 for the `@vaulter/vue` client

## Install

<!-- snippet: shell -->

```bash
composer require axiomasoft/vaulter-drive
php artisan vaulter:install   # publishes config, asks to run the migrations
npm install @vaulter/vue
```

## Quick example

<!-- recipe: packages/drive/tests/Recipes/ReadmeRecipeTest.php#readme-quick-example -->

```php
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Facades\Vaulter;

// $project is any Eloquent model; $user is the signed-in user.
$drive = Vaulter::drive($project)->actingAs($user);

$reports = $drive->folders()->create('Reports');
$file = $drive->files()->upload(UploadSource::fromString('hello', 'hello.txt'), new UploadOptions(parent: $reports));
```

## Packages

| Package | Purpose |
| --- | --- |
| `axiomasoft/vaulter-core` | the kernel: modules, operations, events and outbox, idempotency, errors |
| `axiomasoft/vaulter-media` | content-addressed storage and the blob pins |
| `axiomasoft/vaulter-drive` | folders, files, uploads (tus/S3), access, sharing, activity; the `Vaulter` entry point |
| `axiomasoft/vaulter-documents` | editor, versions, comments, export |
| `axiomasoft/vaulter-webhooks` | outbound webhooks (opt-in module) |
| `axiomasoft/vaulter-azguard` | AzGuard permission resolver |
| `axiomasoft/vaulter-filament` | Filament admin (optional, Laravel 13) |
| `axiomasoft/vaulter` | metapackage: core, media, drive and documents |
| `@vaulter/vue` | TypeScript / Vue 3 client and components |

Each package owns its tables, models and config file (`vaulter-<package>`); webhooks, azguard and
filament are installed separately. See [ADR-0030](docs/adr/0030-package-ownership-and-schema.md).

## Release channels and versions

The public channel contains the MIT-licensed PHP packages listed above and `@vaulter/vue`. The `axiomasoft/vaulter` metapackage requires only core, media, drive and
documents. AzGuard remains excluded from release artifacts until its upstream has a stable tag.

Public PHP siblings use `self.version` and `dev-main` aliases of `1.0.x-dev`.

## Documentation

- [Quick start](docs/getting-started/quick-start.md) · [Installation](docs/getting-started/installation.md)
- [Concepts](docs/concepts/architecture.md) · [Glossary](docs/concepts/glossary.md)
- [Guides](docs/guides/uploads.md) · [Extending](docs/extending/profiles.md) · [Collaboration protocols](docs/extending/collaboration-protocols.md)
- [Operations runbooks](docs/guides/operations.md) · [Security](docs/security/threat-model.md)
- [Contributing](CONTRIBUTING.md) · [Upgrading](UPGRADE.md)

## Monorepo layout

```
vaulter/
├── packages/
│   ├── core/                  # axiomasoft/vaulter-core — the kernel (Vaulter\)
│   ├── media/                 # axiomasoft/vaulter-media — content-addressed (CAS) file/blob layer (Vaulter\Media\)
│   ├── drive/                 # axiomasoft/vaulter-drive — folders/files, uploads, access, sharing (Vaulter\Drive\)
│   ├── documents/             # axiomasoft/vaulter-documents — TipTap documents, versions, comments
│   ├── webhooks/              # axiomasoft/vaulter-webhooks — outbound webhooks (Vaulter\Webhooks\)
│   ├── azguard/                # axiomasoft/vaulter-azguard — AzGuard permission driver
│   ├── vaulter/               # axiomasoft/vaulter — metapackage
│   ├── vue/                   # @vaulter/vue — TypeScript Vue 3 client, composables & components
│   └── filament/              # axiomasoft/vaulter-filament — Filament 5 admin (Vaulter\Filament\)
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
