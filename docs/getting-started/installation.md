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

<!-- snippet: shell -->

```bash
composer require axioma-studio/vaulter
```

`axioma-studio/vaulter-core` alone ships no Drive or document-editing features — it is
the kernel (module system, operations, events with the outbox, idempotency, errors, HTTP and config
frameworks) and owns no domain table. To take only part of the product, require the packages your app
actually needs:

<!-- snippet: shell -->

```bash
# Drive (folders/files/uploads) — pulls in axioma-studio/vaulter-media (CAS) automatically
composer require axioma-studio/vaulter-drive

# Document nodes (TipTap/Yjs editing, versions, comments) — requires vaulter-drive
composer require axioma-studio/vaulter-documents
```

Either command also pulls in `axioma-studio/vaulter-core` as a dependency, so a
plain `composer require axioma-studio/vaulter-core` alone is only useful if you build your own modules
on the kernel.

Three packages are not in the metapackage and are installed on demand. Each requires `vaulter-drive`:

<!-- snippet: shell -->

```bash
# Outbound webhooks (opt-in module, see below)
composer require axioma-studio/vaulter-webhooks

# Admin panel for Filament 5
composer require axioma-studio/vaulter-filament

# AzGuard permission driver
composer require axioma-studio/vaulter-azgard
```

`vaulter-webhooks` opens outbound network traffic, so its module stays off until you switch it on in
`config/vaulter.php` (`'modules' => ['webhooks' => true]`, see [Modules](/concepts/modules)); its tables are
created by `php artisan migrate` either way.

Protected HTTP routes require authentication through `auth:sanctum` by default.
Install and configure Laravel Sanctum in your host (`composer require laravel/sanctum`),
or set `vaulter.routes.middleware.authenticated` to your application's guard.
Anonymous protected requests receive 401; public share links and signed hub callbacks
use independent `public` and `hub` stacks and require no Sanctum token.
Request metrics are added to all three stacks automatically. See [Configuration](/guides/configuration#routes).

Then run the bundled install command, which publishes the config of every installed Vaulter
package and offers to run the migrations:

<!-- snippet: shell -->

```bash
php artisan vaulter:install
```

::: details What `vaulter:install` does
- publishes the config of each installed package (`vaulter-config`, `vaulter-media-config`,
  `vaulter-drive-config`, …); a file that already exists is skipped unless `--force`,
- asks for the auth middleware (or takes `--middleware`) and prints the config line to apply — it never
  edits `config/*.php` or `.env`; choose the permission driver with `vaulter-drive.access.permission_driver`
  (`VAULTER_PERMISSION_DRIVER`),
- asks to run `php artisan migrate` (or runs it with `--migrate`),
- asks to star the repo on GitHub.

Without a terminal it takes the options or the defaults and does not migrate unless `--migrate`
is given. Running it again is safe. See [Commands → `vaulter:install`](/reference/commands#vaulter-install).
:::

### Manual install

Prefer to do it step by step? The install command is equivalent to:

::: code-group

<!-- snippet: shell -->

```bash [publish + migrate]
php artisan vendor:publish --tag=vaulter-config
php artisan migrate
```

<!-- snippet: shell -->

```bash [config only]
# Vaulter's migrations run from vendor/ and are never published,
# so the minimum is just the config file:
php artisan vendor:publish --tag=vaulter-config
php artisan migrate
```

:::

::: info Migrations are not published
Every package runs the migrations of the tables it owns straight from `vendor/`: `vaulter-core` the
outbox and idempotency keys, `vaulter-media` the blobs and their pins, `vaulter-drive` the drive tables,
`vaulter-documents` the document tables, `vaulter-webhooks` the endpoints and deliveries. There is no publish tag for them, so there are no published
copies to drift from the package, and a table you did not install a package for is never created. The
published config lands at `config/vaulter.php`.
:::

## 2. Frontend — the Vue package

Install the Vue components, composables and API client:

<!-- snippet: shell -->

```bash
npm install @vaulter/vue
```

Register the plugin and point it at your API prefix (default `api/vaulter`):

<!-- snippet: host -->

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

<!-- snippet: shell -->

```bash
cp hocuspocus-server/.env.example hocuspocus-server/.env
docker compose -f hocuspocus-server/docker-compose.yml up -d
```

The hub is required when realtime collaboration is enabled. Details and
the full env contract are in [Collaboration → Server](/guides/collaboration).

## Verify the install

A quick sanity check from `tinker`:

<!-- snippet: host -->

```php
use Vaulter\Drive\Facades\Vaulter;

$drive = Vaulter::drive($user);   // any Eloquent model
$drive->root();                 // the auto-provisioned root folder Node
```

If that returns a `Node`, the backend is wired correctly.

## Next steps

- [**Quick Start**](/getting-started/quick-start) — create a folder, upload a file, list a drive.
- [**Configuration**](/guides/configuration) — bind a permission driver and tune the package.
