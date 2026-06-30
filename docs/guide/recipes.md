---
title: Recipes
description: Worked, copy-pasteable Vaulter integrations — multi-tenant SaaS storage, attaching a Drive to a CRM task, custom permission drivers, custom format handlers, swapping the disk to S3, and blob GC.
outline: deep
---

# Recipes

Practical, end-to-end recipes for the common ways teams embed Vaulter. Each one is grounded in
a real contract or config key — see [Architecture](/guide/architecture) for the mental model
and [Configuration](/guide/configuration) for every key in full.

[[toc]]

## Attach a Drive to a CRM task or project {#attach-drive}

The headline use case: give any host entity its own file/folder Drive with **zero migrations**.
`Vaulter::for($entity)` get-or-creates a `v_storages` row keyed by the polymorphic
`(owner_type, owner_id, panel)` triple and provisions a root folder — idempotent, so calling it
twice returns the same storage.

::: code-group

```php [Controller]
use Vaulter\Facades\Vaulter;

final class TaskDriveController
{
    public function index(\App\Models\Task $task)
    {
        // Authorize through your app, then open the Drive for this task.
        $drive = Vaulter::for($task);

        return response()->json([
            'storage' => $drive->storage()->id,
            'nodes'   => $drive->list(), // Collection<Node> of the root's children
        ]);
    }

    public function store(\App\Models\Task $task, \Illuminate\Http\Request $request)
    {
        $drive = Vaulter::for($task);

        $node = $drive->upload(
            $request->file('document'),
            actorId: $request->user()->getKey(),
        );

        return response()->json(['node' => $node->id], 201);
    }
}
```

```vue [Vue]
<script setup lang="ts">
import { VaulterDrive } from '@vaulter/vue'

// The storage id resolved server-side (e.g. from Vaulter::for($task)->storage()->id).
const props = defineProps<{ storageId: string }>()
</script>

<template>
  <VaulterDrive :storage-id="props.storageId" />
</template>
```

:::

For a **dedicated** Drive on a specific entity type — with its own feature flags and a Filament
resource — define a Storage Panel instead of using the `default` panel:

```bash
php artisan vaulter:make-panel task_drive --host="App\\Models\\Task"
```

This scaffolds `app/Vaulter/Panels/TaskDriveStoragePanel.php`. Edit its feature flags, then
register the provider FQCN in `config/vaulter.php`:

```php
// config/vaulter.php
'panels' => [
    \App\Vaulter\Panels\TaskDriveStoragePanel::class,
],
```

```php
// app/Vaulter/Panels/TaskDriveStoragePanel.php
use Vaulter\Panels\Panel;
use Vaulter\Panels\StoragePanelProvider;

final class TaskDriveStoragePanel extends StoragePanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('task_drive')
            ->host(\App\Models\Task::class)
            ->storageType('team')
            ->feature('document_versions', true) // enable in-app rich-text editing
            ->feature('share_links', true);      // enable bearer share links
    }
}
```

Now mount it with the panel id:

```php
$drive = Vaulter::for($task, panel: 'task_drive');
```

::: tip Zero migrations
A panel is a config envelope over the **shared** `v_*` schema — `make-panel` writes **no**
migration and touches **no** config. Registering the FQCN and (only for the rare own-tables
mode) generating migrations are deliberate, separate steps. See
[ADR-0009](/adr/0009-storage-panels).
:::

## Multi-tenant SaaS storage {#multi-tenant}

Vaulter does **not** enforce tenancy — by design ([ADR-0003](/adr/0003-embeddability-data-model)).
The host owns the tenant boundary; Vaulter gives you the seams to express it. There are three
moving parts.

### 1. Add the tenant column

```php
// config/vaulter.php
'tenant' => [
    'enabled' => true,        // VAULTER_TENANT_ENABLED — adds a tenant_id column to the schema
    'column'  => 'tenant_id',
],
```

### 2. Supply a tenant-aware model

Vaulter resolves every model through `config('vaulter.models.*')`. Point one (or all) at your
own subclass that applies your tenant scope — Vaulter never scopes by `tenant_id` itself:

```php
// app/Models/Vaulter/Node.php
namespace App\Models\Vaulter;

use Vaulter\Models\Drive\Node as BaseNode;
use App\Models\Concerns\BelongsToTenant; // your existing global-scope trait

final class Node extends BaseNode
{
    use BelongsToTenant;
}
```

```php
// config/vaulter.php
'models' => [
    // ...
    'node'    => \App\Models\Vaulter\Node::class,
    'storage' => \App\Models\Vaulter\Storage::class,
],
```

### 3. Align dedup scope and ids

Per-tenant dedup is already the default, so a global dedup namespace can't leak the
*existence* of a file across tenants ([ADR-0012](/adr/0012-dedup-scope)):

```php
// config/vaulter.php
'dedup' => ['scope' => 'tenant'], // the default; never use 'global' for untrusted tenants
'ids'   => ['default' => 'ulid'], // ULID aligns with CoreX / AzGuard morph keys (ADR-0011)
```

::: warning Tenant scope is the host's responsibility
If you set `dedup.scope = 'global'` with multiple tenants you accept a cross-tenant existence
side channel. Keep it `tenant` (or `storage`) unless the tenants trust one another. If you swap
in a tenant-aware model, that model — not Vaulter — must apply the scope on *every* query.
:::

## Write a custom permission driver {#permission-driver}

The Drive ACL combines `v_node_grants` with a swappable `HostRoleDriver` lane. To bridge your
app's role system, implement
[`Vaulter\Contracts\Access\HostRoleDriver`](/guide/architecture#authorization). It is
**node-blind**: it never receives a `Node` — only an `Authorizable` actor and an `AclScope`
(panel + workspace coordinates + node owner).

The simplest real driver subclasses the standalone `database` lane and just expands the
subject-set with the actor's roles/teams (so `v_node_grants` rows addressed to a role apply):

```php
namespace App\Vaulter;

use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Access\ValueObjects\Subject;
use Illuminate\Contracts\Auth\Access\Authorizable;

final class TeamRoleDriver extends DatabasePermissionResolver
{
    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array
    {
        $subjects = parent::subjectsFor($actor); // [Subject(actor)]

        foreach ($actor->teams as $team) {
            $subjects[] = new Subject(type: 'team', id: $team->getKey());
        }

        return $subjects;
    }
}
```

A fuller driver also answers the role lane and the super-admin override:

```php
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;

final class AppRoleDriver implements HostRoleDriver
{
    public function override(Authorizable $actor, AclScope $scope): ?Effect
    {
        return $actor->is_super_admin ? Effect::Allow : null; // absolute, step 1
    }

    public function resolve(Subject $subject, Authorizable $actor, AclScope $scope): ?Decision
    {
        // Raise access from a host role — folded as roleAllow into max(aclAllow, roleAllow).
        // It can RAISE access but never override a deny (the final veto still applies).
        if ($actor->canEditIn($scope->workspaceType, $scope->workspaceId)) {
            // Decision::allow(level) defaults bits to the level's bitmask;
            // pass explicit bits only to narrow them.
            return Decision::allow(GrantLevel::Writer);
        }

        return null; // nothing to say — the ACL is the sole authority
    }

    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array
    {
        return [Subject::ofModel($actor)];
    }

    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool
    {
        return $actor->canCreateIn($workspaceType, $workspaceId);
    }
}
```

Register it as a named driver and select it:

```php
// config/vaulter.php
'drivers' => [
    'permission' => env('VAULTER_PERMISSION_DRIVER', 'app'),
],

'permission_drivers' => [
    'database' => \Vaulter\Drivers\Permission\DatabasePermissionResolver::class,
    'gate'     => \Vaulter\Drivers\Permission\GatePermissionResolver::class,
    'spatie'   => \Vaulter\Drivers\Permission\SpatiePermissionResolver::class,
    'azgard'   => \Vaulter\Drivers\Permission\AzgardPermissionResolver::class,
    'app'      => \App\Vaulter\AppRoleDriver::class, // your driver
],
```

::: tip Use a shipped lane first
For Laravel Gate, Spatie Permission v8, or AzGuard you do **not** need a custom class — set
`drivers.permission` to `gate` / `spatie` / `azgard` and fill the matching config block
(`gate.*` / `spatie.*` / `azgard.*`). The AzGuard lane needs a host adapter for
`Contracts\Access\AzgardGuard` — see
[Troubleshooting](/guide/troubleshooting#azgard-morphs).
:::

## Write a custom format handler {#format-handler}

A `DocumentHandler` claims mime types / extensions and tells the client how to mount a node
(editor, preview, or download). The `DocumentHandlerRegistry` resolves the best one by
mime → extension → capability → priority, falling back to the `DownloadOnlyHandler`. Adding a
format is **one class + one register call — no migration, no core change**
([H.5 / ADR-0004](/adr/0004-content-format)).

```php
namespace App\Vaulter\Handlers;

use Vaulter\Contracts\Documents\DocumentHandler;
use Vaulter\ValueObjects\Documents\DocumentCapabilities;
use Vaulter\ValueObjects\Documents\FileDescriptor;
use Vaulter\ValueObjects\Documents\HandlerUi;

final class CadHandler implements DocumentHandler
{
    public function id(): string { return 'cad'; }

    /** @return list<string> */
    public function mimeTypes(): array { return ['application/acad', 'image/vnd.dwg']; }

    /** @return list<string> */
    public function extensions(): array { return ['dwg', 'dxf']; }

    // Higher wins among handlers matching the same file; DownloadOnly sits lowest.
    public function priority(): int { return 100; }

    public function capabilities(FileDescriptor $file): DocumentCapabilities
    {
        return new DocumentCapabilities(
            editable: false,
            previewable: true,
            downloadable: true,
            thumbnailable: true,
            collaborative: false,
        );
    }

    public function ui(FileDescriptor $file): HandlerUi
    {
        // The Vue component + open route + props the client routes the node to.
        return new HandlerUi(
            component: 'CadViewer',
            route: "nodes/{$file->nodeId}/download",
            props: ['mime' => $file->canonicalMime()],
        );
    }
}
```

Register it any of three ways — config FQCN (cache-safe), a runtime call, or a tagged binding:

::: code-group

```php [config]
// config/vaulter.php
'handlers' => [
    \Vaulter\Documents\Handlers\TipTapHandler::class,
    \App\Vaulter\Handlers\CadHandler::class, // before the fallbacks
    \Vaulter\Documents\Handlers\PreviewHandler::class,
    \Vaulter\Documents\Handlers\DownloadOnlyHandler::class,
],
```

```php [runtime]
// A service provider's boot()
use Vaulter\Facades\Vaulter;

Vaulter::registerHandler(\App\Vaulter\Handlers\CadHandler::class);
```

```php [tagged]
// A service provider's register()
$this->app->tag([\App\Vaulter\Handlers\CadHandler::class], 'vaulter.handlers');
```

:::

::: details Confirm the descriptor that ships to the client
`GET nodes/{node}/handler` returns the resolved handler's `id`, capabilities and `HandlerUi`
for a node — exactly what the Vue client reads to pick an editor/preview implementation. (This
route is gated on the `document_versions` feature.)
:::

## Swap the storage disk to S3 {#s3-disk}

Vaulter's byte plane is a content-addressed store over a Flysystem disk. To use S3 you usually
only change config — no custom class needed.

### Point Vaulter at an S3 disk

```php
// config/filesystems.php — a standard Laravel S3 disk
'disks' => [
    's3-vaulter' => [
        'driver' => 's3',
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION'),
        'bucket' => env('VAULTER_S3_BUCKET'),
    ],
],
```

```dotenv
# .env
VAULTER_DISK=s3-vaulter
```

`config('vaulter.storage.default_disk')` is the canonical CAS disk used when a storage row does
not pin its own `disk`. The shipped `FlysystemStorageDriver` already does the right thing on S3:
a conditional create (`If-None-Match: *`), short-lived signed URLs via `temporaryUrl`, and the
scope-qualified key layout.

::: danger Never version the CAS bucket
The CAS bucket must be **unversioned**. Do **not** use S3 lifecycle/versioning as a
blob-deletion mechanism — Vaulter owns deletion through its two-phase GC. The only S3 lifecycle
rule you should add is `AbortIncompleteMultipartUpload` as a backstop for abandoned resumable
uploads ([ADR-0006](/adr/0006-content-addressed-blobs-gc)).
:::

### Swap a CAS service for object-store specifics

If you need behaviour the default driver doesn't cover, implement
`Vaulter\Contracts\Cas\StorageDriver` (or `ContentStore` / `BlobStore`) and bind it:

```php
// config/vaulter.php
'cas' => [
    'content_store'  => \Vaulter\Cas\Sha256ContentStore::class,
    'storage_driver' => \App\Vaulter\S3ChecksumDriver::class, // your driver
    'blob_store'     => \Vaulter\Cas\BlobStore::class,
],
```

The `StorageDriver` surface: `disk()`, `put($key, $stream, $disk, $ifNoneMatch, $options)`,
`get()`, `exists()`, `delete()`, `temporaryUrl($key, $expiresAt, $disk)`.

## Custom export format {#custom-export}

The built-in `html` and `markdown` exporters render a document's TipTap content. Add your own
(PDF, DOCX, …) by implementing `Vaulter\Contracts\Documents\DocumentExporter`:

```php
namespace App\Vaulter;

use Vaulter\Contracts\Documents\DocumentExporter;
use Vaulter\ValueObjects\Documents\TipTapContent;

final class PdfExporter implements DocumentExporter
{
    public function format(): string { return 'pdf'; }
    public function mimeType(): string { return 'application/pdf'; }
    public function extension(): string { return 'pdf'; }

    public function export(TipTapContent $content): string
    {
        // Binary formats return an encoded string (e.g. base64) + the matching mime.
        return MyPdfLibrary::fromTiptap($content->toArray())->toBytes();
    }
}
```

Register it via config or at runtime:

::: code-group

```php [config]
// config/vaulter.php
'exporters' => [
    'html'     => \Vaulter\Export\HtmlExporter::class,
    'markdown' => \Vaulter\Export\MarkdownExporter::class,
    'pdf'      => \App\Vaulter\PdfExporter::class,
],
```

```php [runtime]
use Vaulter\Facades\Vaulter;

Vaulter::registerExporter(new \App\Vaulter\PdfExporter());
```

:::

It is then reachable at `GET nodes/{node}/export?format=pdf` and via the Vue
`useDocumentExport` composable.

## Custom share-token shape {#share-token}

By default share tokens are random strings. To mint short, hashed, or prefixed tokens, implement
`Vaulter\Contracts\Sharing\ShareTokenGenerator` (a token must be unique and fit the token
column, ≤ 64 chars):

```php
namespace App\Vaulter;

use Vaulter\Contracts\Sharing\ShareTokenGenerator;

final class ShortTokenGenerator implements ShareTokenGenerator
{
    public function generate(): string
    {
        return \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12));
    }
}
```

```php
// config/vaulter.php
'share' => [
    'token_generator' => \App\Vaulter\ShortTokenGenerator::class,
    'url_base'        => env('VAULTER_SHARE_URL_BASE', '/share'),
    'prefix_length'   => 8,
],
```

::: info Hash-only storage
Only the `sha256` of the token is ever stored (`token_hash`), with a short `token_prefix` for
owner-side lookup. The raw token is returned to the creator exactly once and never re-exposed
([ADR-0013](/adr/0013-two-sharing-channels)).
:::

## Blob garbage collection on a schedule {#blob-gc}

Trashing or replacing files leaves blobs unreferenced. The two-phase GC tombstones them, then
sweeps after a grace window; a separate reconcile job repairs `ref_count` drift.

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
use Illuminate\Support\Facades\Schedule;

// Trash retention → permanently force-deletes nodes past trash.retention_days.
Schedule::command('vaulter:purge-trash')->daily();

// Two-phase blob GC: tombstone unreferenced blobs, sweep tombstoned past grace.
Schedule::command('vaulter:gc-blobs')->hourly();

// Repair ref_count drift + flag dangling rows (run less often, off-peak).
// Add --orphans to ALSO disk-walk for orphan objects (report-only; see Storage & Blobs).
Schedule::command('vaulter:reconcile-blobs')->dailyAt('03:00');

// Reclaim resumable-upload sessions older than uploads.session_ttl_hours.
Schedule::command('vaulter:sweep-uploads')->hourly();
```

The grace window is `config('vaulter.gc.grace_hours')` (default 24, floored at 1h). It **must**
be ≥ your worst-case multipart-commit latency so an in-flight upload can resurrect a tombstoned
blob first.

```bash
# Override the grace window ad hoc, and also clear crashed-staging PENDING rows.
php artisan vaulter:gc-blobs --grace=48 --pending
```

::: danger --pending sweeps crashed uploads
`--pending` sweeps `PENDING` blobs left by a crashed staging step. Run it only when you are
sure no upload is mid-flight, or keep the grace window generous — a too-short grace can sweep a
blob a slow multipart commit was about to reference.
:::

## Reliability — outbox & webhooks {#webhooks}

The `reliability` feature stages each Drive domain event in `v_outbox` (after-commit), then a
relay fans it out into signed `v_webhook_deliveries` rows per active subscribed endpoint:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay-outbox')->everyMinute();    // outbox → deliveries → queue
Schedule::command('vaulter:retry-webhooks')->everyFiveMinutes(); // retry failed deliveries
Schedule::command('vaulter:prune-webhooks')->daily();        // reclaim settled audit history
```

Subscriber endpoints are managed through the `webhook-endpoints` routes (gated by the
`WebhookEndpointPolicy` manage ability). Each delivery POST carries an HMAC-SHA256 signature
(`X-Vaulter-Signature`), a timestamp (`X-Vaulter-Timestamp`) and the event name
(`X-Vaulter-Event`); consumers **dedupe on the `event_id`** in the payload, since delivery is
at-least-once. Tune `config('vaulter.webhooks.*')` (`max_attempts`, `backoff`,
`disable_after_failures`).

### Pruning settled outbox & webhook history

The relayed outbox rows and terminal webhook deliveries are an append-only audit trail that
otherwise grows forever. `vaulter:prune-webhooks` reclaims the **settled** rows past a
retention window — and *only* the settled ones:

```bash
# Prune settled rows older than config('vaulter.webhooks.retention_days') (default 30):
php artisan vaulter:prune-webhooks

# Override the window, or preview the exact counts without deleting anything:
php artisan vaulter:prune-webhooks --days=7
php artisan vaulter:prune-webhooks --dry-run
```

It deletes exactly two kinds of row that never change again:

- **dispatched outbox rows** — status `dispatched`, by `dispatched_at`;
- **terminal deliveries** — status `delivered` or `dead` (DLQ), by `updated_at` (the
  terminalisation time).

In-flight rows are **never** touched: a `pending` outbox row (the relay still owns it) and a
`pending`/`failed` delivery (the due-retry sweep still owns it) are left alone regardless of
age. `--dry-run` reports the counts it *would* remove without deleting a row. The command is
gated on the `reliability` feature — with the feature off it no-ops with a notice (the
`v_outbox` / `v_webhook_*` tables may not exist). The retention default lives at
`config('vaulter.webhooks.retention_days')` (30, floored at 0). Schedule it alongside the
relay/retry commands above.

## Testing with the shipped doubles {#testing}

Vaulter ships test doubles under `Vaulter\Testing\*` so a consumer can boot without wiring a
real role system:

- **`NullRoleDriver`** — an open driver (every actor is a super-admin) for tests / local dev.
  It is rejected in production, so it can never silently open a prod install.
- **`FakeAzgardGuard`** — a `Contracts\Access\AzgardGuard` double for testing the `azgard` lane
  without AzGuard installed.

```php
// In a Pest test, force the open driver for the duration of the test.
config()->set('vaulter.drivers.permission', 'null'); // non-production only

$drive = Vaulter::for($task);
$node  = $drive->upload('hello', name: 'note.txt');

expect($node->name)->toBe('note.txt');
```

## Next

- [Architecture](/guide/architecture) — the layers and seams these recipes plug into.
- [Troubleshooting](/guide/troubleshooting) — what goes wrong and how to fix it.
- [Configuration](/guide/configuration) — every config key in full.
