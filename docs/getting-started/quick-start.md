---
title: Quick Start
description: A minimal working Vaulter drive in under a minute — open a drive for an entity, create a folder, upload a file, list it, then embed the Vue UI.
outline: deep
---

# Quick Start

This is the ≤60-second happy path: from a fresh install to a working drive — a folder, an
uploaded file, and a listing — then the Vue UI on top.

::: tip Prerequisite
You have installed `axioma-studio/vaulter-drive` (this walkthrough uses `Vaulter::drive()`,
`DriveHandle`, uploads — all Drive features) and run the migrations. If not, do
[Installation](/getting-started/installation) first (it is two commands).
:::

## 1. Give the user access

Vaulter ships with the standalone **`database`** host-role driver as the default, so a clean
Laravel app needs **no driver wiring** to get started — access is decided purely by the
per-node grants (`v_node_grants`) that the Vaulter-owned `AccessResolver` reads. A new drive has
no grant, so even its owner entity's user sees nothing until it gets one. Grant the user the owner
role on the root once, for example when the drive's entity is created:

<!-- recipe: packages/drive/tests/Recipes/DriveRecipeTest.php#quick-start-grant -->

```php
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;

// A new drive grants nobody: give the user the owner role on its root once, as the system.
$system = Vaulter::drive($project)->asSystem('drive owner setup');
$system->access()->grant($system->root(), new GrantInput(
    subject: Subject::ofModel($user),
    role: RoleRegistry::OWNER,
));
```


You only touch this when you want roles from *your* app to count. To swap the lane, set one
config key:

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'access' => [
    'permission_driver' => 'gate', // database (default) | gate | spatie | azguard | <your FQCN>
],
```

::: warning Production
The default `database` driver is safe for production. The open `null` driver is rejected by
config validation outside local/testing — never deploy a panel bound to it. Choosing and
configuring a driver is covered in [Host-role driver](/extending/permission-drivers).
:::

## 2. Open a drive and use it

`Vaulter::drive($entity)` get-or-creates the entity's drive (and its root folder) and returns
a `DriveHandle` — the handle for the whole drive; `actingAs($user)` makes every call run with
that user's access. Everything below is a real method on it:

<!-- recipe: packages/drive/tests/Recipes/DriveRecipeTest.php#quick-start-open -->

```php
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Facades\Vaulter;

// $project is any Eloquent model; $user is the signed-in user who acts on the drive.
$drive = Vaulter::drive($project)->actingAs($user);

// Create a folder under the root.
$reports = $drive->folders()->create('Reports');

// Upload bytes — a path, an UploadedFile, a stream or a string, wrapped in an UploadSource.
$node = $drive->files()->upload(
    UploadSource::fromUploadedFile(request()->file('document')),
    new UploadOptions(parent: $reports),
);

// List the direct children of a folder (null = the root).
$page = $drive->nodes()->children($reports);   // Vaulter\Data\Page of NodeSnapshot
```

That is a fully working drive. A handful of the other `DriveHandle` methods:

<!-- recipe: packages/drive/tests/Recipes/DriveRecipeTest.php#quick-start-methods -->

```php
use Vaulter\Drive\Data\CopyOptions;
use Vaulter\Drive\Data\UploadSource;

$drive->nodes()->rename($node, 'Q3 report.pdf');
$drive->nodes()->move($node, $reports);
$drive->nodes()->copy($node, $reports, new CopyOptions(name: 'Q3 report (copy).pdf'));
$drive->nodes()->trash($node);                         // soft trash (recoverable)
$drive->trash()->restore($node);
$drive->files()->addRevision($node, UploadSource::fromString($newBytes, 'Q3 report.pdf')); // a new revision of a file
```

::: details Where do uploads land?
`upload()` streams the bytes into the content-addressed blob store: it computes the `sha256`,
deduplicates within the configured scope, and links a `v_files` / file-version row to the new
node. You never deal with disks or paths directly. See [Concepts → Blobs](/concepts/storage-and-blobs).
:::

## 3. Expose it over the API (one route)

The Drive REST routes are already mounted under `api/vaulter` (the `routes.prefix`). Your app
just needs a way to hand a storage id to the frontend — typically resolved from the host
entity:

<!-- snippet: host -->

```php
// routes/web.php (or an Inertia/controller response)
use Vaulter\Drive\Facades\Vaulter;

Route::get('/projects/{project}/drive', function (App\Models\Project $project) {
    return inertia('Drive', [
        'driveId' => Vaulter::drive($project)->snapshot()->id,
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

With the [plugin installed](/getting-started/installation#_2-frontend-—-the-vue-package), embed the
file-manager component and pass it the `driveId`:

::: code-group

<!-- snippet: host -->

```vue [VaulterDrive.vue]
<script setup lang="ts">
import { VaulterDrive } from '@vaulter/vue'

defineProps<{ driveId: string }>()

function openNode(node: { id: string; kind: string }) {
  // open/preview/edit the node in your app
}
</script>

<template>
  <VaulterDrive :drive-id="driveId" @open-node="openNode" />
</template>
```

<!-- snippet: host -->

```ts [useNodes (lower-level)]
import { useNodes } from '@vaulter/vue'

// The composable behind VaulterDrive — drive your own UI with it.
const { nodes, parentId, loading, error, refresh, navigate, createFolder, rename, move, trash } =
  useNodes(driveId)

await createFolder('Reports')
await refresh()
```

:::

`<VaulterDrive>` gives you a breadcrumb, a toolbar (new folder, upload, search, view toggle)
and the node listing, with row actions wired to the API. It emits `open-node` for non-folder
nodes so your app decides how to open them.

## Done — where next?

- [**Files & folders**](/concepts/nodes-and-revisions) — the full CRUD surface, REST and Vue.
- [**Uploads**](/guides/uploads) — resumable (tus / S3-multipart) uploads for large files.
- [**Authorization**](/concepts/access-model) — the two-lane model and choosing a driver.
- [**Configuration**](/guides/configuration) — every `config/vaulter.php` section.
- [**Storage Panels**](/concepts/drives-and-profiles) — give an entity an isolated, configured drive.
