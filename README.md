# Vaulter

Files, folders and collaborative documents for any Laravel app — authorized by your own roles.

## Requirements

- PHP 8.4, Laravel 12 or 13
- PostgreSQL 15+ (primary), MySQL 8.0+, or SQLite for development
- Node 22+ for the `@vaulter/vue` client and the optional collaboration hub

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
| `axioma-studio/vaulter-core` | the kernel: modules, operations, events and outbox, idempotency, errors |
| `axioma-studio/vaulter-tenant` | optional binding to an existing Tenant runtime; [host composition](packages/tenant/README.md) |
| `axioma-studio/vaulter-media` | content-addressed storage and the blob pins |
| `axioma-studio/vaulter-drive` | folders, files, uploads (tus/S3), access, sharing, activity; the `Vaulter` entry point |
| `axioma-studio/vaulter-documents` | editor, versions, comments, export |
| `axioma-studio/vaulter-collaboration` | commercial realtime editing with committed save receipts (opt-in module); the Node hub lives in `packages/collaboration/hub` |
| `axioma-studio/vaulter-webhooks` | outbound webhooks (opt-in module) |
| `axioma-studio/vaulter-azgard` | AzGuard permission resolver |
| `axioma-studio/vaulter-filament` | Filament admin (optional, Laravel 13) |
| `axioma-studio/vaulter` | metapackage: core, media, drive and documents |
| `@vaulter/vue` | TypeScript / Vue 3 client and components |
| `@vaulter/vue-collaboration` | commercial browser client for realtime editing: update queue and save status |

Each package owns its tables, models and config file (`vaulter-<package>`); webhooks, collaboration, azgard and
filament are installed separately. See [ADR-0030](docs/adr/0030-package-ownership-and-schema.md) and
[ADR-0031](docs/adr/0031-collaboration-package-and-durability.md). The optional collaboration hub (Node, Docker image
`ghcr.io/axiomasoft/vaulter-collaboration-hub`) lives in `packages/collaboration/hub/`.

## Release channels and versions

The public channel contains the MIT-licensed PHP packages listed above except collaboration,
and `@vaulter/vue`. The `axioma-studio/vaulter` metapackage requires only core, media, drive and
documents. AzGuard remains excluded from release artifacts until its upstream has a stable tag.

Realtime co-editing is a commercial add-on (`axioma-studio/vaulter-collaboration`). Its PHP
package uses a private split and Private Packagist for Vendors; `@vaulter/vue-collaboration`
uses restricted npm access with provenance disabled. Both the hub and the Vue add-on declare
`UNLICENSED`; the hub is a private, image-only workspace distributed through private GHCR.
The PHP add-on declares `proprietary`. Their LICENSE placeholders grant no license; the
commercial agreement and final legal license text must be supplied separately by Axioma Studio.
Third-party dependency licenses continue to apply to their respective code.

Public PHP siblings use `self.version` and `dev-main` aliases of `1.0.x-dev`. Private PHP,
Vue add-on and hub releases have independent version lines. The PHP add-on requires public
core, drive and documents at `^1.0` (and media at `^1.0` for development); the Vue add-on requires
`@vaulter/vue` at `^1.0`. Matching version numbers do not require releases to happen together.

## Documentation

- [Quick start](docs/getting-started/quick-start.md) · [Installation](docs/getting-started/installation.md)
- [Concepts](docs/concepts/architecture.md) · [Glossary](docs/concepts/glossary.md)
- [Guides](docs/guides/uploads.md) · [Extending](docs/extending/profiles.md) · [Realtime collaboration](docs/guides/collaboration.md)
- [Operations runbooks](docs/guides/operations.md) · [Security](docs/security/threat-model.md)
- [Contributing](CONTRIBUTING.md) · [Upgrading](UPGRADE.md)

## Monorepo layout

```
vaulter/
├── packages/
│   ├── core/                  # axioma-studio/vaulter-core — the kernel (Vaulter\)
│   ├── media/                 # axioma-studio/vaulter-media — content-addressed (CAS) file/blob layer (Vaulter\Media\)
│   ├── drive/                 # axioma-studio/vaulter-drive — folders/files, uploads, access, sharing (Vaulter\Drive\)
│   ├── documents/             # axioma-studio/vaulter-documents — TipTap/Yjs documents, versions, comments
│   ├── collaboration/         # axioma-studio/vaulter-collaboration — realtime editing (Vaulter\Collaboration\)
│   │   └── hub/               # @vaulter/collaboration-hub — the Node Hocuspocus hub (+ Docker)
│   ├── webhooks/              # axioma-studio/vaulter-webhooks — outbound webhooks (Vaulter\Webhooks\)
│   ├── azgard/                # axioma-studio/vaulter-azgard — AzGuard permission driver
│   ├── vaulter/               # axioma-studio/vaulter — metapackage
│   ├── vue/                   # @vaulter/vue — TypeScript Vue 3 client, composables & components
│   ├── vue-collaboration/     # @vaulter/vue-collaboration — Yjs transport, update queue, save status
│   └── filament/              # axioma-studio/vaulter-filament — Filament 5 admin (Vaulter\Filament\)
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

Public packages: [MIT](LICENSE) © Axioma Studio. Commercial collaboration artifacts use
the private licensing channel described above and their own LICENSE placeholders.
