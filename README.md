# Vaulter

Files, folders and collaborative documents for any Laravel app — authorized by your own roles.

## Requirements

- PHP 8.4, Laravel 13
- PostgreSQL 18 (primary), MySQL 9.7 LTS, or SQLite for development
- Node 26 for the `@vaulter/vue` client and the optional collaboration hub

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
| `axiomasoft/vaulter-collaboration` | commercial realtime editing with committed save receipts (opt-in module); the Node hub lives in `packages/collaboration/hub` |
| `axiomasoft/vaulter-webhooks` | outbound webhooks (opt-in module) |
| `axiomasoft/vaulter-azguard` | AzGuard permission resolver |
| `axiomasoft/vaulter-filament` | Filament admin (optional, Laravel 13) |
| `axiomasoft/vaulter` | metapackage: core, media, drive and documents |
| `@vaulter/vue` | TypeScript / Vue 3 client and components |
| `@vaulter/vue-collaboration` | commercial browser client for realtime editing: update queue and save status |

Each package owns its tables, models and config file (`vaulter-<package>`); webhooks, collaboration, azguard and
filament are installed separately. See [ADR-0030](docs/adr/0030-package-ownership-and-schema.md) and
[ADR-0031](docs/adr/0031-collaboration-package-and-durability.md). The optional collaboration hub (Node, Docker image
`ghcr.io/axiomasoft/vaulter-collaboration-hub`) lives in `packages/collaboration/hub/`.

## Release channels and versions

The public channel contains the MIT-licensed PHP packages listed above except collaboration,
and `@vaulter/vue`. The `axiomasoft/vaulter` metapackage requires only core, media, drive and
documents. AzGuard remains excluded from release artifacts until its upstream has a stable tag.

Realtime co-editing is a commercial add-on (`axiomasoft/vaulter-collaboration`). Its PHP
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
│   ├── core/                  # axiomasoft/vaulter-core — the kernel (Vaulter\)
│   ├── media/                 # axiomasoft/vaulter-media — content-addressed (CAS) file/blob layer (Vaulter\Media\)
│   ├── drive/                 # axiomasoft/vaulter-drive — folders/files, uploads, access, sharing (Vaulter\Drive\)
│   ├── documents/             # axiomasoft/vaulter-documents — TipTap/Yjs documents, versions, comments
│   ├── collaboration/         # axiomasoft/vaulter-collaboration — realtime editing (Vaulter\Collaboration\)
│   │   └── hub/               # @vaulter/collaboration-hub — the Node Hocuspocus hub (+ Docker)
│   ├── webhooks/              # axiomasoft/vaulter-webhooks — outbound webhooks (Vaulter\Webhooks\)
│   ├── azguard/                # axiomasoft/vaulter-azguard — AzGuard permission driver
│   ├── vaulter/               # axiomasoft/vaulter — metapackage
│   ├── vue/                   # @vaulter/vue — TypeScript Vue 3 client, composables & components
│   ├── vue-collaboration/     # @vaulter/vue-collaboration — Yjs transport, update queue, save status
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

Public packages: [MIT](LICENSE) © Axioma Studio. Commercial collaboration artifacts use
the private licensing channel described above and their own LICENSE placeholders.
