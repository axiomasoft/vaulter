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
PHP `^8.4`, Laravel `^13.0`, and (for the UI) Vue `^3.4` / Node `>=26`. See
[Compatibility](/reference/compatibility) for the supported versions and release lines.
:::

## 1. Backend — the Laravel package

For the full product install the metapackage. It has no code of its own and requires
`axiomasoft/vaulter-core`, `-media`, `-drive` and `-documents` at exactly its own version:

<!-- snippet: shell -->

```bash
composer require axiomasoft/vaulter
```

`axiomasoft/vaulter-core` alone ships no Drive or document-editing features — it is
the kernel (module system, operations, events with the outbox, idempotency, errors, HTTP and config
frameworks) and owns no domain table. To take only part of the product, require the packages your app
actually needs:

<!-- snippet: shell -->

```bash
# Drive (folders/files/uploads) — pulls in axiomasoft/vaulter-media (CAS) automatically
composer require axiomasoft/vaulter-drive

# Document nodes (TipTap/Yjs editing, versions, comments) — requires vaulter-drive
composer require axiomasoft/vaulter-documents
```

Either command also pulls in `axiomasoft/vaulter-core` as a dependency, so a
plain `composer require axiomasoft/vaulter-core` alone is only useful if you build your own modules
on the kernel.

Three packages are not in the metapackage and are installed on demand. Each requires `vaulter-drive`:

<!-- snippet: shell -->

```bash
# Outbound webhooks (opt-in module, see below)
composer require axiomasoft/vaulter-webhooks

# Admin panel for Filament 5
composer require axiomasoft/vaulter-filament

# AzGuard permission driver
composer require axiomasoft/vaulter-azguard
```

`vaulter-webhooks` opens outbound network traffic, so its module stays off until you switch it on in
`config/vaulter.php` (`'modules' => ['webhooks' => true]`, see [Modules](/concepts/modules)); its tables are
created by `php artisan migrate` either way. Commercial realtime editing is an optional add-on.

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
- runs `php artisan vaulter:doctor` and reports its findings (they never fail the install),
- offers the link to star the repository on GitHub.

Without a terminal it takes the options or the defaults and does not migrate unless `--migrate`
is given. Running it again is safe. See [Commands → `vaulter:install`](/reference/commands#vaulter-install).
:::

### Manual install

Prefer to do it step by step? Publish the config of each installed package (one tag per package), then
migrate and check the install:

<!-- snippet: shell -->

```bash
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-media-config
php artisan vendor:publish --tag=vaulter-drive-config
php artisan vendor:publish --tag=vaulter-documents-config
php artisan migrate
php artisan vaulter:doctor
```

::: info Migrations are not published
Every package runs the migrations of the tables it owns straight from `vendor/`: `vaulter-core` the
outbox and idempotency keys, `vaulter-media` the blobs and their pins, `vaulter-drive` the drive tables,
`vaulter-documents` the document tables, `vaulter-webhooks` the endpoints and deliveries. There is no publish tag for them, so there are no published
copies to drift from the package, and a table you did not install a package for is never created. Each
package publishes its own config file: `config/vaulter.php` for the kernel, `config/vaulter-media.php`,
`config/vaulter-drive.php`, `config/vaulter-documents.php` and so on.
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

## 3. Collaboration (optional)

Commercial add-on operations are documented in its private guide.

## Verify the install

A quick sanity check from `tinker`. There is no signed-in user there, so the call names the system actor
explicitly (every Vaulter call runs as a user or as the system):

<!-- snippet: host -->

```php
use Vaulter\Drive\Facades\Vaulter;

$drive = Vaulter::drive($user);         // any Eloquent model
$drive->asSystem('install check')->root(); // the auto-provisioned root folder
```

If that returns a `Vaulter\Drive\Data\Snapshots\NodeSnapshot`, the backend is wired correctly.

## Next steps

- [**Quick Start**](/getting-started/quick-start) — create a folder, upload a file, list a drive.
- [**Configuration**](/guides/configuration) — bind a permission driver and tune the package.
