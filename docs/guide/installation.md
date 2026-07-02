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
[Requirements](/guide/installation) for the full matrix.
:::

## 1. Backend — the Laravel package

`axioma-studio/vaulter-core` alone ships no Drive or document-editing features — it is
the shared kernel (authorization, CAS contracts, sharing, panels). Require the packages
your app actually needs:

```bash
# Drive (folders/files/uploads) — pulls in axioma-studio/vaulter-media (CAS) automatically
composer require axioma-studio/vaulter-drive

# Document nodes (TipTap/Yjs editing, versions, comments) — independent of Drive
composer require axioma-studio/vaulter-documents
```

Either command also pulls in `axioma-studio/vaulter-core` as a dependency, so a
plain `composer require axioma-studio/vaulter-core` alone is only useful if you plan to
implement every domain contract yourself without Drive or Documents.

Then run the bundled install command, which publishes the config, publishes the migrations
and offers to run them:

```bash
php artisan vaulter:install
```

::: details What `vaulter:install` does
It is the standard Spatie package-tools install command:

- publishes `config/vaulter.php` (tag `vaulter-config`),
- publishes the package migrations (tag `vaulter-migrations`),
- asks to run `php artisan migrate`,
- asks to star the repo on GitHub.

Nothing is published silently — you are prompted at each step.
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
[Frontend overview](/guide/vue) for the alternatives (`createVaulterClient` +
`provideVaulterClient`) and SSR wiring.

::: tip Asset build
`@vaulter/vue` ships built ESM/UMD bundles and TypeScript types — there is no extra build
step beyond your app's own bundler (Vite, etc.). Vue `^3.4` is a peer dependency.
:::

## 3. Collaboration server (optional)

Realtime editing has two transports. The default `reverb` driver needs **no extra service**
(it rides Laravel Reverb). The alternative `hocuspocus` driver uses a small Node.js Hocuspocus
hub, shipped with a `Dockerfile` and `docker-compose.yml` under `hocuspocus-server/`:

```bash
cp hocuspocus-server/.env.example hocuspocus-server/.env
docker compose -f hocuspocus-server/docker-compose.yml up -d
```

You only need this when `config('vaulter.collaboration.driver')` is `hocuspocus`. Details and
the full env contract are in [Collaboration → Server](/guide/collaboration).

## Verify the install

A quick sanity check from `tinker`:

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($user);   // any Eloquent model
$drive->root();                 // the auto-provisioned root folder Node
```

If that returns a `Node`, the backend is wired correctly.

## Next steps

- [**Quick Start**](/guide/quick-start) — create a folder, upload a file, list a drive.
- [**Configuration**](/guide/configuration) — bind a permission driver and tune the package.
