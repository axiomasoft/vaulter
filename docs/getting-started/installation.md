---
title: Installation
description: Install vaulter-core via Composer, publish the config and migrations, run them, then add the @vaulter/vue frontend package.
outline: deep
---

# Installation

Vaulter has two installable parts: the Laravel **backend** package and the Vue **frontend**
package. The optional realtime collaboration uses a small Node service. Install the backend
first.

::: tip Requirements
PHP `^8.4`, Laravel `^12.0 || ^13.0`, and (for the UI) Vue `^3.4` / Node `>=20`. See
[Requirements](/getting-started/installation) for the full matrix.
:::

## 1. Backend — the Laravel package

For the full product install the metapackage. It has no code of its own and requires
`axioma-studio/vaulter-core`, `-media`, `-drive` and `-documents` at exactly its own version:

```bash
composer require axioma-studio/vaulter
```

`axioma-studio/vaulter-core` alone ships no Drive or document-editing features — it is
the shared kernel (authorization, CAS contracts, sharing, panels). To take only part of the
product, require the packages your app actually needs:

```bash
# Drive (folders/files/uploads) — pulls in axioma-studio/vaulter-media (CAS) automatically
composer require axioma-studio/vaulter-drive

# Document nodes (TipTap/Yjs editing, versions, comments) — requires vaulter-drive
composer require axioma-studio/vaulter-documents
```

Either command also pulls in `axioma-studio/vaulter-core` as a dependency, so a
plain `composer require axioma-studio/vaulter-core` alone is only useful if you plan to
implement every domain contract yourself without Drive or Documents.

Protected HTTP routes require authentication through `auth:sanctum` by default.
Install and configure Laravel Sanctum in your host (`composer require laravel/sanctum`),
or set `vaulter.routes.middleware.authenticated` to your application's guard.
Anonymous protected requests receive 401; public share links and signed hub callbacks
use independent `public` and `hub` stacks and require no Sanctum token.
Request metrics are added to all three stacks automatically. Published 0.2 configs
with `vaulter.routes.middleware.authenticated` still override the authenticated stack; remove that legacy
key to adopt the new default. See [Configuration](/guides/configuration#routes).

Then run the bundled install command, which publishes the config of every installed Vaulter
package and offers to run the migrations:

```bash
php artisan vaulter:install
```

::: details What `vaulter:install` does
- publishes the config of each installed package (`vaulter-config`, `vaulter-media-config`,
  `vaulter-drive-config`, …); a file that already exists is skipped unless `--force`,
- asks for the auth middleware and the permission driver (or takes `--middleware` / `--driver`)
  and prints the `.env` and config lines to apply — it never edits `config/*.php` or `.env`,
- asks to run `php artisan migrate` (or runs it with `--migrate`),
- asks to star the repo on GitHub.

Without a terminal it takes the options or the defaults and does not migrate unless `--migrate`
is given. Running it again is safe. See [Commands → `vaulter:install`](/reference/commands#vaulter-install).
:::

### Manual install

Prefer to do it step by step? The install command is equivalent to:

::: code-group

```bash [publish + migrate]
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-migrations
php artisan migrate
```

```bash [config only]
# Vaulter's migrations are auto-discovered and run even without publishing,
# so the minimum is just the config file:
php artisan vendor:publish --tag=vaulter-config
php artisan migrate
```

:::

::: info Migrations are auto-discovered
The service provider calls `discoversMigrations()->runsMigrations()`, so the package
migrations run from `vendor/` without being published. Publish them with
`--tag=vaulter-migrations` only when you want to **edit** them (e.g. to tweak the schema
before a custom panel). The published config lands at `config/vaulter.php`.
:::

::: warning Publishing migrations switches the canonical mode — don't run both
`vendor:publish --tag=vaulter-migrations` generates **new timestamp-prefixed filenames**
for every migration (e.g. `2026_06_13_000007_create_v_storages_table.php` becomes
`2026_07_10_101542_create_v_storages_table.php`). Laravel tracks executed migrations by
**filename**, so once you publish, the vendor-discovered copies (still active through
`discoversMigrations()->runsMigrations()`) are no longer the same migrations Laravel has
already run — the next `php artisan migrate` will try to run the published copies a
**second time** and fail with `SQLSTATE[42S01]: table already exists`.

Publishing migrations is a one-way switch: once you publish `vaulter-migrations` to edit
the schema, migrate **only** from the published copies from then on. Do not leave the
vendor auto-run active alongside a published copy of the same migration.
:::

## 2. Frontend — the Vue package

Install the Vue components, composables and API client:

```bash
npm install @vaulter/vue
```

Register the plugin and point it at your API prefix (default `api/vaulter`):

```js
// resources/js/app.js
import { VaulterPlugin } from '@vaulter/vue'

app.use(VaulterPlugin, { baseUrl: '/api/vaulter' })
```

This globally registers the Drive components (`VaulterDrive`, `VaulterUploader`,
`VaulterCommentsPanel`, …) and provides the API client to every composable. See
[Frontend overview](/guides/vue) for the alternatives (`createVaulterClient` +
`provideVaulterClient`) and SSR wiring.

::: tip Asset build
`@vaulter/vue` ships built ESM/UMD bundles and TypeScript types — there is no extra build
step beyond your app's own bundler (Vite, etc.). Vue `^3.4` is a peer dependency.
:::

## 3. Collaboration server (optional)

Realtime editing uses the `hocuspocus` driver and a small Node.js Hocuspocus
hub, shipped with a `Dockerfile` and `docker-compose.yml` under `hocuspocus-server/`:

```bash
cp hocuspocus-server/.env.example hocuspocus-server/.env
docker compose -f hocuspocus-server/docker-compose.yml up -d
```

The hub is required when realtime collaboration is enabled. Details and
the full env contract are in [Collaboration → Server](/guides/collaboration).

## Verify the install

A quick sanity check from `tinker`:

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($user);   // any Eloquent model
$drive->root();                 // the auto-provisioned root folder Node
```

If that returns a `Node`, the backend is wired correctly.

## Next steps

- [**Quick Start**](/getting-started/quick-start) — create a folder, upload a file, list a drive.
- [**Configuration**](/guides/configuration) — bind a permission driver and tune the package.
