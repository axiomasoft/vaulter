---
title: Trash & restore
description: The Vaulter trash lifecycle — soft-delete, restore, force-delete, retention windows and the vaulter:purge-trash command.
outline: deep
---

# Trash & restore

Deleting a node in the Drive is **reversible** by default: `trash()` soft-deletes
the node (and its subtree), which can be listed, restored, or — once its retention
window elapses — permanently purged. This page covers that lifecycle and the
`vaulter:purge-trash` command that enforces retention.

::: tip Two kinds of delete
`trash()` is recoverable (sets `trashed_at`). `forceDelete()` is permanent and
detaches the bytes. Prefer `trash()` everywhere in app code and let the scheduled
purge apply your retention policy — see [Drive · forceDelete](/guide/drive#forcedelete).
:::

## The lifecycle

```
live node ──trash()──▶ trashed (trashed_at set) ──restore()──▶ live node
                              │
                              └── retention elapses ──▶ vaulter:purge-trash ──▶ gone
```

A trashed node keeps its row (and subtree) until either it is restored or its
retention window passes and the purge command force-deletes it.

### Trashing

```php
use Vaulter\Facades\Vaulter;

$drive = Vaulter::for($project);

$drive->trash($node);          // soft-delete — dispatches NodeTrashed
```

Trashing a folder trashes its whole subtree. The node disappears from `list()` and
search but remains in the trash view. The drive root cannot be trashed, and trashing a
node that is already in the trash raises `InvalidDestinationException`
(409 `invalid_destination`).

### Restoring

```php
$drive->restore($node);        // back to live — dispatches NodeRestored
```

The node returns to the folder it was trashed from when that folder is still a live
folder of the drive; otherwise it is restored under the root. If a live sibling already
uses its name there, `NodeNameConflictException` is raised — rename and retry. Only a
trashed node can be restored: anything else raises `NodeNotTrashedException`
(409 `node_not_trashed`).

### Force-deleting

```php
$drive->forceDelete($node);    // permanent — dispatches NodePurged
```

Force-delete only purges from the trash: a live node raises `NodeNotTrashedException`
(trash it first), and the root is never deleted. It detaches every version↔blob edge in the subtree (the bytes become
[GC](/guide/recipes)-eligible) and releases the storage quota.

::: danger Irreversible
`forceDelete()` cannot be undone. There is no "restore from purge".
:::

## Retention

How long a trashed node survives before it becomes purge-eligible is controlled by
`config('vaulter-drive.trash.retention_days')`:

```php
// config/vaulter-drive.php
'trash' => [
    'retention_days' => 30,   // default 30; 0 = purge as soon as trashed
],
```

- **Default:** 30 days.
- **Floor:** 0. `0` means a trashed node is eligible for purge immediately.

The value is read through `Vaulter\Drive\Configuration\DriveConfig::trashRetentionDays()`.

## Scheduled purge

Permanently delete trashed nodes whose retention window has elapsed with the
**`vaulter:purge-trash`** Artisan command:

```bash
php artisan vaulter:purge-trash
```

It finds the trash **roots** whose `trashed_at` is older than
`now() - retention_days` and force-deletes each subtree.

::: info Only trash roots are processed
A trashed node whose parent is *also* trashed is skipped — purging the ancestor's
subtree already covers it (no double-purge).
:::

### Overriding the window per run

The `--days` option overrides the configured retention for a single run (floored
at 0):

```bash
# Purge anything trashed more than 7 days ago, ignoring the config default.
php artisan vaulter:purge-trash --days=7

# Purge everything currently in the trash.
php artisan vaulter:purge-trash --days=0
```

### Scheduling it

Run the purge on a schedule so retention is enforced automatically:

::: code-group
```php [routes/console.php]
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:purge-trash')->daily();
```

```php [Console Kernel]
protected function schedule(Schedule $schedule): void
{
    $schedule->command('vaulter:purge-trash')->daily();
}
```
:::

::: warning Purge releases bytes, GC reclaims them
`vaulter:purge-trash` detaches the version↔blob edges, but the underlying CAS
objects are reclaimed by the separate **blob GC** pass. Schedule both — see
[Blob GC](/guide/recipes).
:::

## The trash view (REST)

The trashed nodes for a storage are listed (cursor-paginated) over REST. Only nodes
the actor may **restore** are returned:

```http
GET /api/vaulter/storages/{storage}/trash
```

In the browser, `useNodes` exposes the trash view and restore:

```ts
import { useNodes } from '@vaulter/vue'

const { trashed, restore } = useNodes(storageId)

await trashed()             // the trash listing
await restore(nodeId)       // bring a node back
```

See the full route contract — params, response shape and the `Restore`/`Delete`
ability gates — in the [REST API reference](/reference/rest-api#trash).

## See also

- [Drive](/guide/drive) — `trash()` / `restore()` / `forceDelete()` on the handle
- [REST API reference](/reference/rest-api#drive) — trash & restore routes
- [Blob GC](/guide/recipes) — reclaiming the bytes a purge detaches
