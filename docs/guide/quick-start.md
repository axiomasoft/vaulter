---
title: Quick Start
description: A minimal working Vaulter drive in under a minute — open a drive for an entity, create a folder, upload a file, list it, then embed the Vue UI.
outline: deep
---

# Quick Start

This is the ≤60-second happy path: from a fresh install to a working drive — a folder, an
uploaded file, and a listing — then the Vue UI on top.

::: tip Prerequisite
You have installed `axioma-studio/vaulter-drive` (this walkthrough uses `Vaulter::for()`,
`DriveHandle`, uploads — all Drive features) and run the migrations. If not, do
[Installation](/guide/installation) first (it is two commands).
:::

## 1. (Authorization works out of the box)

Vaulter ships with the standalone **`database`** host-role driver as the default, so a clean
Laravel app needs **no authorization wiring** to get started — access is decided purely by the
per-node grants (`v_node_grants`) that the Vaulter-owned `AccessResolver` reads.

You only touch this when you want roles from *your* app to count. To swap the lane, set one
config key:

```php
// config/vaulter.php
'access' => [
    'permission_driver' => 'gate', // database (default) | gate | spatie | azgard | <your FQCN>
],
```

::: warning Production
The default `database` driver is safe for production. The open `null` driver is rejected by
config validation outside local/testing — never deploy a panel bound to it. Choosing and
configuring a driver is covered in [Host-role driver](/guide/permission-drivers).
:::

## 2. Open a drive and use it

`Vaulter::for($entity)` get-or-creates the entity's storage (and its root folder) and returns
a `DriveHandle` — the ergonomic handle for the whole drive. Everything below is a real
method on it:

```php
use Vaulter\Facades\Vaulter;

// $project is any Eloquent model — Vaulter::for() owns the storage to it.
$drive = Vaulter::for($project);

// Create a folder under the root.
$reports = $drive->createFolder('Reports');

// Upload bytes — a string, an SplFileInfo/UploadedFile, a stream resource,
// or a PSR-7 StreamInterface all work. $into defaults to the root.
$node = $drive->upload(
    source: request()->file('document'),
    into: $reports,
);

// List the direct children of a folder (null = the root).
$drive->list($reports);   // Illuminate\Database\Eloquent\Collection<Node>
```

That is a fully working drive. A handful of the other `DriveHandle` methods:

```php
$drive->rename($node, 'Q3 report.pdf');
$drive->move($node, into: $reports);
$drive->copy($node, into: $reports, name: 'Q3 report (copy).pdf');
$drive->trash($node);           // soft trash (recoverable)
$drive->restore($node);
$drive->forceDelete($node);     // permanent
$drive->uploadVersion($node, source: $newBytes); // new version onto a file
```

::: details Where do uploads land?
`upload()` streams the bytes into the content-addressed blob store: it computes the `sha256`,
deduplicates within the configured scope, and links a `v_files` / file-version row to the new
node. You never deal with disks or paths directly. See [Concepts → Blobs](/guide/storage-and-blobs).
:::

## 3. Expose it over the API (one route)

The Drive REST routes are already mounted under `api/vaulter` (the `routes.prefix`). Your app
just needs a way to hand a storage id to the frontend — typically resolved from the host
entity:

```php
// routes/web.php (or an Inertia/controller response)
use Vaulter\Facades\Vaulter;

Route::get('/projects/{project}/drive', function (App\Models\Project $project) {
    return inertia('Drive', [
        'storageId' => Vaulter::for($project)->storage()->getKey(),
    ]);
});
```

The mounted Drive endpoints the frontend talks to include:

```
GET    api/vaulter/v1/drives/{drive}/nodes       list a folder
POST   api/vaulter/v1/drives/{drive}/folders     create a folder
POST   api/vaulter/v1/drives/{drive}/files       upload a file
PATCH  api/vaulter/v1/nodes/{node}               rename (If-Match)
POST   api/vaulter/v1/nodes/{node}/move          move (If-Match)
POST   api/vaulter/v1/nodes/{node}/trash         trash (If-Match)
```

Changing an existing node needs its current `ETag` in `If-Match`.

See the full list in the [API routes reference](/reference/rest-api).

## 4. Drop in the Vue UI

With the [plugin installed](/guide/installation#_2-frontend-the-vue-package), embed the
file-manager component and pass it the `storageId`:

::: code-group

```vue [VaulterDrive.vue]
<script setup lang="ts">
import { VaulterDrive } from '@vaulter/vue'

defineProps<{ storageId: string }>()

function openNode(node: { id: string; kind: string }) {
  // open/preview/edit the node in your app
}
</script>

<template>
  <VaulterDrive :storage-id="storageId" @open-node="openNode" />
</template>
```

```ts [useNodes (lower-level)]
import { useNodes } from '@vaulter/vue'

// The composable behind VaulterDrive — drive your own UI with it.
const { nodes, parentId, loading, error, refresh, navigate, createFolder, rename, move, trash } =
  useNodes(storageId)

await createFolder('Reports')
await refresh()
```

:::

`<VaulterDrive>` gives you a breadcrumb, a toolbar (new folder, upload, search, view toggle)
and the node listing, with row actions wired to the API. It emits `open-node` for non-folder
nodes so your app decides how to open them.

## Done — where next?

- [**Files & folders**](/guide/drive) — the full CRUD surface, REST and Vue.
- [**Uploads**](/guide/uploads) — resumable (tus / S3-multipart) uploads for large files.
- [**Authorization**](/guide/authorization) — the two-lane model and choosing a driver.
- [**Configuration**](/guide/configuration) — every `config/vaulter.php` section.
- [**Storage Panels**](/guide/storage-panels) — give an entity an isolated, configured drive.
