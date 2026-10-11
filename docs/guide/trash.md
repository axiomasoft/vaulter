---
title: Trash & restore
description: The Vaulter trash lifecycle — soft-delete, restore, force-delete, retention windows and the vaulter:trash:purge command.
outline: deep
---

# Trash & restore

Deleting a node in the Drive is **reversible** by default: `trash()` soft-deletes
the node (and its subtree), which can be listed, restored, or — once its retention
window elapses — permanently purged. This page covers that lifecycle and the
`vaulter:trash:purge` command that enforces retention.

::: tip Two kinds of delete
`trash()` is recoverable (sets `trashed_at`). `forceDelete()` is permanent and
detaches the bytes. Prefer `trash()` everywhere in app code and let the scheduled
purge apply your retention policy — see [Drive · forceDelete](/guide/drive#forcedelete).
:::

## The lifecycle

```
live node ──trash()──▶ trashed (trashed_at set) ──restore()──▶ live node
                              │
                              └── retention elapses ──▶ vaulter:trash:purge ──▶ gone
```

A trashed node keeps its row (and subtree) until either it is restored or its
retention window passes and the purge command force-deletes it.

### Trash roots

Every trashed node carries `trash_root_id`: the node the trash operation started from. The root
points at itself, the members trashed with it point at the root. The trash lists **only roots**,
retention counts from the root, and a restore brings back **only the members trashed with that
root** — a descendant that was trashed on its own earlier stays in the trash with its own root.
Purging a root deletes its whole subtree, including such earlier-trashed descendants that still
sit inside it (the same as permanently deleting a folder in Drive or Box).

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

The node returns to the folder it was trashed from. When that folder is gone or is itself in the
trash, the restore **does not move the node silently**: it raises
`RestoreDestinationRequiredException` (409 `restore_destination_required`). Pass the destination
explicitly and it is checked like any create (`Create` on the folder, an active folder of the
drive):

```php
$drive->trash()->restore($node, new RestoreOptions(destination: $folder));
```

Only a trash root can be restored: a member trashed with a folder raises
`NodeTrashedByAncestorException` (409 `node_trashed_by_ancestor`, `context.trash_root_id`) —
restore the root instead. If a live sibling already uses the name there, `OnNameConflict` decides
(`Rename` by default). Only a trashed node can be restored: anything else raises
`NodeNotTrashedException` (409 `node_not_trashed`).

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
**`vaulter:trash:purge`** Artisan command:

```bash
php artisan vaulter:trash:purge
```

It finds the trash **roots** whose `trashed_at` is older than
`now() - retention_days` and force-deletes each subtree.

::: info Only trash roots are processed
Eligible are the roots (`trash_root_id = id`) whose `trashed_at` is past the window. A root's
purge removes its whole subtree, so a member is never purged twice, and a descendant trashed
earlier on its own has its own root and its own clock.
:::

### Overriding the window per run

The `--days` option overrides the configured retention for a single run (floored
at 0):

```bash
# Purge anything trashed more than 7 days ago, ignoring the config default.
php artisan vaulter:trash:purge --days=7

# Purge everything currently in the trash.
php artisan vaulter:trash:purge --days=0
```

### Scheduling it

Run the purge on a schedule so retention is enforced automatically:

::: code-group
```php [routes/console.php]
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:trash:purge')->daily();
```

```php [Console Kernel]
protected function schedule(Schedule $schedule): void
{
    $schedule->command('vaulter:trash:purge')->daily();
}
```
:::

::: warning Purge releases bytes, GC reclaims them
`vaulter:trash:purge` detaches the version↔blob edges, but the underlying CAS
objects are reclaimed by the separate **blob GC** pass. Schedule both — see
[Blob GC](/guide/recipes).
:::

## The trash view (REST)

The trash roots of a drive are listed (cursor-paginated) over REST v1, limited to the
roots the caller may see. Each item's `etag` is the tag restore and purge expect — a
trashed node cannot be read through `GET v1/nodes/{node}`:

```http
GET /api/vaulter/v1/drives/{drive}/trash
POST /api/vaulter/v1/nodes/{node}/restore      If-Match: <etag from the listing>
DELETE /api/vaulter/v1/nodes/{node}            If-Match: <etag from the listing>
```

A restore or purge with a stale tag answers `412 node_sequence_mismatch` and changes
nothing — a purge in particular removes no row, quota or stored byte. The same expectation
is available in PHP: `$drive->trash()->purge($node, expectedSequence: $snapshot->sequence)` and
`new RestoreOptions(expectedSequence: $snapshot->sequence)`.

In the browser, `useNodes` exposes the trash view and restore:

```ts
import { useNodes } from '@vaulter/vue'

const { trashed, restore } = useNodes(storageId)

await trashed()             // the trash listing
await restore(nodeId)       // bring a node back
```

See the full route contract — params, response shape and the `restore`/`delete`
ability gates — in the [REST API reference](/reference/rest-api#trash).

## See also

- [Drive](/guide/drive) — `trash()` / `restore()` / `forceDelete()` on the handle
- [REST API reference](/reference/rest-api#drive) — trash & restore routes
- [Blob GC](/guide/recipes) — reclaiming the bytes a purge detaches
