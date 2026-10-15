---
title: Recipes
description: Worked, copy-pasteable Vaulter integrations — multi-tenant SaaS storage, attaching a Drive to a CRM task, custom permission drivers, custom format handlers, swapping the disk to S3, and blob GC.
outline: deep
---

# Recipes

Practical, end-to-end recipes for the common ways teams embed Vaulter. Each one is grounded in
a real contract or config key — see [Architecture](/concepts/architecture) for the mental model
and [Configuration](/guides/configuration) for every key in full.

[[toc]]

## Attach a Drive to a CRM task or project {#attach-drive}

The headline use case: give any host entity its own file/folder Drive with **zero migrations**.
`Vaulter::drive($entity)` get-or-creates a `v_storages` row keyed by the polymorphic
`(owner_type, owner_id, panel)` triple and provisions a root folder — idempotent, so calling it
twice returns the same storage.

::: code-group

<!-- snippet: host -->

```php [Controller]
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Facades\Vaulter;

final class TaskDriveController
{
    public function index(\App\Models\Task $task)
    {
        // Authorize through your app, then open the Drive for this task as the signed-in user.
        $drive = Vaulter::drive($task)->actingAs(request()->user());

        return response()->json([
            'storage' => $drive->snapshot()->id,
            'nodes'   => $drive->nodes()->children()->items, // the root's children, as NodeSnapshot
        ]);
    }

    public function store(\App\Models\Task $task, \Illuminate\Http\Request $request)
    {
        $drive = Vaulter::drive($task)->actingAs($request->user());

        $node = $drive->files()->upload(UploadSource::fromUploadedFile($request->file('document')));

        return response()->json(['node' => $node->id], 201);
    }
}
```

<!-- snippet: host -->

```vue [Vue]
<script setup lang="ts">
import { VaulterDrive } from '@vaulter/vue'

// The storage id resolved server-side (e.g. from Vaulter::drive($task)->snapshot()->id).
const props = defineProps<{ driveId: string }>()
</script>

<template>
  <VaulterDrive :drive-id="props.driveId" />
</template>
```

:::

For a **dedicated** Drive on a specific entity type — with its own feature flags and a Filament
resource — define a Storage Profile instead of using the `default` panel:

<!-- snippet: shell -->

```bash
php artisan vaulter:make:profile task_drive --host="App\\Models\\Task"
```

This scaffolds `app/Vaulter/Profiles/TaskDriveStoragePanel.php`. Edit its feature flags, then
register the provider FQCN in `config/vaulter.php`:

<!-- snippet: config -->

```php
// config/vaulter.php
'profiles' => ['providers' => [
    \App\Vaulter\Profiles\TaskDriveStoragePanel::class,
]],
```

<!-- snippet: host -->

```php
// app/Vaulter/Profiles/TaskDriveStoragePanel.php
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileProvider;

final class TaskDriveStoragePanel extends ProfileProvider
{
    public function profile(Profile $panel): Profile
    {
        return $panel
            ->id('task_drive')
            ->host(\App\Models\Task::class)
            ->storageType('team')
            ->feature('share_links', true);      // enable bearer share links
    }
}
```

Now mount it with the panel id:

<!-- snippet: host -->

```php
$drive = Vaulter::drive($task, profile: 'task_drive');
```

::: tip Zero migrations
A profile uses the shared `v_*` schema. `vaulter:make:profile` writes the provider;
register its FQCN in config. It creates no migration.
:::

## Multi-tenant SaaS storage {#multi-tenant}

Vaulter does **not** enforce tenancy — by design ([ADR-0003](/adr/0003-embeddability-data-model)).
The host owns the tenant boundary; Vaulter gives you the seams to express it. There are three
moving parts.

### 1. Add the tenant column

<!-- snippet: config -->

```php
// config/vaulter.php
'tenancy' => [
    'enabled' => true,        // VAULTER_TENANT_ENABLED — adds a tenant_id column to the schema
],
```

### 2. Supply a tenant-aware model

Vaulter resolves every model through `config('vaulter.models.*')`. Point one (or all) at your
own subclass that applies your tenant scope — Vaulter never scopes by `tenant_id` itself:

<!-- snippet: host -->

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

<!-- snippet: config -->

```php
// config/vaulter.php
'models' => [
    // ...
    'node'    => \App\Models\Vaulter\Node::class,
    'drive' => \App\Models\Vaulter\Storage::class,
],
```

### 3. Align dedup scope and ids

Per-tenant dedup is already the default, so a global dedup namespace can't leak the
*existence* of a file across tenants ([ADR-0012](/adr/0012-dedup-scope)):

<!-- snippet: config -->

```php
// config/vaulter-media.php
// config/vaulter-media.php
'dedup' => ['scope' => 'tenant'], // the default; never use 'global' for untrusted tenants
// config/vaulter.php
'ids'   => ['strategy' => 'ulid'], // ULID aligns with CoreX / AzGuard morph keys (ADR-0011)
```

::: warning Tenant scope is the host's responsibility
If you set `vaulter-media.dedup.scope = 'global'` with multiple tenants you accept a cross-tenant existence
side channel. Keep it `tenant` (or `storage`) unless the tenants trust one another. If you swap
in a tenant-aware model, that model — not Vaulter — must apply the scope on *every* query.
:::

## Write a custom permission driver {#permission-driver}

The Drive ACL combines `v_node_grants` with a swappable `PermissionDriver` lane. To bridge your
app's role system, implement
[`Vaulter\Contracts\Access\PermissionDriver`](/concepts/architecture#authorization). It is
**node-blind**: it never receives a `Node` — only an `Authorizable` actor and an `AccessScope`
(profile + the drive's owner and id + tenant).

The simplest real driver subclasses the standalone `database` lane and just expands the
subject-set with the actor's roles/teams (so `v_node_grants` rows addressed to a role apply):

<!-- snippet: host -->

```php
namespace App\Vaulter;

use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\ValueObjects\Subject;
use Illuminate\Contracts\Auth\Access\Authorizable;

final class TeamRoleDriver extends DatabasePermissionDriver
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

<!-- snippet: host -->

```php
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Enums\Access\Effect;
use Vaulter\Access\Roles\RoleRegistry;

final class AppRoleDriver implements PermissionDriver
{
    public function __construct(private RoleRegistry $roles) {}

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        return $actor->is_super_admin ? Effect::Allow : null; // absolute, step 1
    }

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        // Raise access from a host role — folded as roleAllow into max(aclAllow, roleAllow).
        // It can RAISE access but never override a deny (the final veto still applies).
        if ($actor->canEditIn($scope->driveOwner->type, $scope->driveOwner->id)) {
            // A Decision carries a role from the registry; the abilities come from the role.
            return new Decision($this->roles->latest(RoleRegistry::WRITER));
        }

        return null; // nothing to say — the ACL is the sole authority
    }

    /** @return list<Subject> */
    public function subjectsFor(Authorizable $actor): array
    {
        return [Subject::ofModel($actor)];
    }

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return $actor->canCreateIn($scope->driveOwner->type, $scope->driveOwner->id);
    }
}
```

Register it as a named driver and select it:

<!-- snippet: config -->

```php
// config/vaulter.php
'access' => [
    'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'app'),

    'permission_drivers' => [
    'database' => \Vaulter\Access\Drivers\DatabasePermissionDriver::class,
    'gate'     => \Vaulter\Access\Drivers\GatePermissionDriver::class,
    'spatie'   => \Vaulter\Access\Drivers\SpatiePermissionDriver::class,
    'app'      => \App\Vaulter\AppRoleDriver::class, // your driver
    ],
],
```

::: tip Use a shipped lane first
For Laravel Gate, Spatie Permission v8, or AzGuard you do **not** need a custom class — set
`access.permission_driver` to `gate` / `spatie` / `azgard` and fill the matching config block
(`vaulter.access.gate.*` / `vaulter.access.spatie.*` / `vaulter-azgard.*`). For AzGuard install
`composer require axioma-studio/vaulter-azgard`: its provider registers the driver
and adapter automatically. The user model must implement `AzgardBackedUser` — see
[Permission drivers](/extending/permission-drivers).
:::

## Write a custom format handler {#format-handler}

A `FileHandler` claims mime types / extensions and tells the client how to mount a node
(editor, preview, or download). The core-owned `Vaulter\Handlers\FileHandlerRegistry` resolves
the best one by mime → extension → capability → priority, falling back to the `DownloadOnlyHandler`.
Adding a
format is **one class + one register call — no migration, no core change**
([H.5 / ADR-0004](/adr/0004-content-format)).

<!-- snippet: host -->

```php
namespace App\Vaulter\Handlers;

use Vaulter\Contracts\Handlers\FileHandler;
use Vaulter\ValueObjects\Handlers\FileDescriptor;
use Vaulter\ValueObjects\Handlers\ContentCapabilities;
use Vaulter\ValueObjects\Handlers\ViewerDescriptor;

final class CadHandler implements FileHandler
{
    public function key(): string { return 'acme/cad'; }

    /** @return list<string> */
    public function mimeTypes(): array { return ['application/acad', 'image/vnd.dwg']; }

    /** @return list<string> */
    public function extensions(): array { return ['dwg', 'dxf']; }

    // Higher wins among handlers matching the same file; DownloadOnly sits lowest.
    public function priority(): int { return 100; }

    public function capabilities(FileDescriptor $file): ContentCapabilities
    {
        return new ContentCapabilities(
            editable: false,
            previewable: true,
            downloadable: true,
            thumbnailable: true,
            collaborative: false,
        );
    }

    public function viewer(FileDescriptor $file): ViewerDescriptor
    {
        // The viewer key + props the client routes the node to.
        return new ViewerDescriptor(
            key: 'acme/cad',
            props: ['mime' => $file->canonicalMime()],
        );
    }
}
```

Register it any of three ways — config FQCN (cache-safe), a runtime call, or a tagged binding:

::: code-group

<!-- snippet: config -->

```php [config]
// config/vaulter.php
'handlers' => [
    \App\Vaulter\Handlers\CadHandler::class, // before the fallbacks
    \Vaulter\Handlers\Builtin\PreviewHandler::class,
    \Vaulter\Handlers\Builtin\DownloadOnlyHandler::class,
],
```

<!-- snippet: host -->

```php [runtime]
// A service provider's boot()
use Vaulter\Facades\Vaulter;

Vaulter::handlers()->register(\App\Vaulter\Handlers\CadHandler::class);
```

<!-- snippet: host -->

```php [tagged]
// A service provider's register()
$this->app->tag([\App\Vaulter\Handlers\CadHandler::class], 'vaulter.file_handlers');
```

:::

::: details Confirm the descriptor that ships to the client
The `presentation` of `GET v1/nodes/{node}` carries the resolved handler's `id`, capabilities and
viewer descriptor for a node — exactly what the Vue client reads to pick an editor/preview
implementation.
:::

## Swap the storage disk to S3 {#s3-disk}

Vaulter's byte plane is a content-addressed store over a Flysystem disk. To use S3 you usually
only change config — no custom class needed.

### Point Vaulter at an S3 disk

<!-- snippet: host -->

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

`config('vaulter-media.disk')` is the canonical CAS disk used when a storage row does
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

<!-- snippet: config -->

```php
// config/vaulter-media.php
'content_store'  => \Vaulter\Media\Cas\Sha256ContentStore::class,
'storage_driver' => \App\Vaulter\S3ChecksumDriver::class, // your driver
'blob_store'     => \Vaulter\Media\Cas\BlobStore::class,

```

The `StorageDriver` surface: `disk()`, `put($key, $stream, $disk, $ifNoneMatch, $options)`,
`get()`, `exists()`, `delete()`, `temporaryUrl($key, $expiresAt, $disk)`.

## Custom export format {#custom-export}

The built-in `html` and `markdown` exporters render rich-text and Markdown documents. Add your
own (PDF, DOCX, …) by implementing `Vaulter\Documents\Contracts\DocumentExporter` — scaffold one
with `php artisan vaulter:make:exporter Pdf`:

<!-- snippet: host -->

```php
namespace App\Vaulter\Exporters;

use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\DocumentExporter;
use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Exceptions\UnsupportedDocumentContentException;

final class PdfExporter implements DocumentExporter
{
    public function key(): string { return 'acme/pdf'; }
    public function format(): string { return 'pdf'; }
    public function mimeType(): string { return 'application/pdf'; }
    public function extension(): string { return 'pdf'; }
    public function documentTypes(): array { return ['vaulter/rich-text']; }

    public function export(DocumentContent $content, DocumentType $type): string
    {
        // The contract is opaque — narrow to what this exporter understands.
        if (!$content instanceof TipTapContent) {
            throw UnsupportedDocumentContentException::forExporter(
                $this->format(), TipTapContent::class, $content::class,
            );
        }

        // The file's bytes, rendered from the decoded content only.
        return MyPdfLibrary::fromTiptap($content->toArray())->toBytes();
    }
}
```

Register it by class name in config, or from a provider before the application has booted:

::: code-group

<!-- snippet: config -->

```php [config]
// config/vaulter-documents.php
'exporters' => [
    \Vaulter\Documents\Export\HtmlExporter::class,
    \Vaulter\Documents\Export\MarkdownExporter::class,
    \App\Vaulter\Exporters\PdfExporter::class,
],
```

<!-- snippet: host -->

```php [provider]
use Vaulter\Documents\Facades\Documents;

Documents::exporters()->register(\App\Vaulter\Exporters\PdfExporter::class);
```

:::

It is then reachable at `GET v1/documents/{node}/export/pdf` and via the Vue
`useDocument(nodeId).exportAs('pdf')` composable.

## Custom share-token shape {#share-token}

By default share tokens are random strings. To mint short, hashed, or prefixed tokens, implement
`Vaulter\Contracts\Sharing\ShareTokenGenerator` (a token must be unique and fit the token
column, ≤ 64 chars):

<!-- snippet: host -->

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

<!-- snippet: config -->

```php
// config/vaulter.php
'sharing' => [
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

<!-- snippet: host -->

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
// Only needed with vaulter.schedule.enabled=false; otherwise the packages schedule these already.
use Illuminate\Support\Facades\Schedule;

// Trash retention → permanently force-deletes nodes past trash.retention_days.
Schedule::command('vaulter:trash:purge')->daily();

// Two-phase blob GC: tombstone unreferenced blobs, sweep tombstoned past grace.
Schedule::command('vaulter:blobs:gc')->hourly();

// Repair ref_count drift + flag dangling rows (run less often, off-peak).
// Add --orphans to ALSO disk-walk for orphan objects (report-only; see Storage & Blobs).
Schedule::command('vaulter:blobs:reconcile')->dailyAt('03:00');

// Reclaim resumable-upload sessions older than uploads.session_ttl_hours.
Schedule::command('vaulter:uploads:sweep')->hourly();
```

The grace window is `config('vaulter-media.gc.grace_hours')` (default 24, floored at 1h). It **must**
be ≥ your worst-case multipart-commit latency so an in-flight upload can resurrect a tombstoned
blob first.

<!-- snippet: shell -->

```bash
# Override the grace window ad hoc, and also clear crashed-staging PENDING rows.
php artisan vaulter:blobs:gc --grace=48 --pending
```

::: danger --pending sweeps crashed uploads
`--pending` sweeps `PENDING` blobs left by a crashed staging step. Run it only when you are
sure no upload is mid-flight, or keep the grace window generous — a too-short grace can sweep a
blob a slow multipart commit was about to reference.
:::

## Reliability — outbox & webhooks {#webhooks}

Every domain event is written to `v_outbox` in the transaction of its operation; the core relay
hands it to the outbox consumers, and the webhooks module's consumer fans it out into signed
`v_webhook_deliveries` rows per active subscribed endpoint (see [The outbox](/concepts/events-outbox)):

<!-- snippet: host -->

```php
// Only needed with vaulter.schedule.enabled=false; otherwise the packages schedule these already.
use Illuminate\Support\Facades\Schedule;

Schedule::command('vaulter:relay')->everyMinute()->withoutOverlapping(); // outbox → consumers → deliveries → queue
Schedule::command('vaulter:outbox:prune')->hourly();              // outbox retention without loss
Schedule::command('vaulter:webhooks:retry')->everyFiveMinutes(); // retry failed deliveries
Schedule::command('vaulter:webhooks:prune')->daily();        // reclaim terminal delivery history
```

Subscriber endpoints are managed through the `webhook-endpoints` routes (gated by the
`WebhookEndpointPolicy` manage ability). Each delivery POST is signed per
[Standard Webhooks](/guides/webhooks#headers-and-signature) (`webhook-id`, `webhook-timestamp`,
`webhook-signature`); consumers **dedupe on `webhook-id`** (the envelope `id`), since delivery is
at-least-once. Tune `config('vaulter.webhooks.*')` (`retry_schedule`, `retry_jitter`,
`disable_after_days`) and `config('vaulter.outbox.*')`.

### Pruning settled outbox & webhook history

`vaulter:outbox:prune` deletes outbox events past `outbox.retention_seconds` once no delivery of
them is open and every durable consumer has reconciled past them; dead outbox deliveries stay
`outbox.dead_retention_seconds`. `vaulter:webhooks:prune` reclaims the terminal webhook deliveries
past a retention window — and *only* those:

<!-- snippet: shell -->

```bash
# Prune terminal deliveries older than config('vaulter.webhooks.retention_days') (default 30):
php artisan vaulter:webhooks:prune

# Override the window, or preview the exact count without deleting anything:
php artisan vaulter:webhooks:prune --days=7
php artisan vaulter:webhooks:prune --dry-run
```

It deletes **terminal deliveries** — status `delivered` or `dead` (DLQ), by `updated_at` (the
terminalisation time). A `pending`/`failed` delivery (the due-retry sweep still owns it) is left
alone regardless of age. `--dry-run` reports the count it *would* remove without deleting a row.
While the webhooks module is inactive the command no-ops with a notice. The retention default lives
at `config('vaulter.webhooks.retention_days')` (30, floored at 0).

## Testing with the shipped doubles {#testing}

Vaulter ships test doubles under `Vaulter\Testing\*` so a consumer can boot without wiring a
real role system:

- **`NullPermissionDriver`** — an open driver (every actor is a super-admin) for tests / local dev.
  It is rejected in production, so it can never silently open a prod install.
- **`Vaulter\Azgard\Testing\FakeAzgardGuard`** — a guard double for the `azgard` lane;
  the bridge and its Composer dependencies must be installed.

<!-- recipe: packages/drive/tests/Recipes/DriveRecipeTest.php#testing-open-driver -->

```php
// In a Pest test, force the open driver for the duration of the test.
config()->set('vaulter.access.permission_driver', 'null'); // non-production only

$drive = Vaulter::drive($task)->actingAs($user);
$node = $drive->files()->upload(UploadSource::fromString('hello', 'note.txt'));

expect($node->name)->toBe('note.txt');
```

## Next

- [Architecture](/concepts/architecture) — the layers and seams these recipes plug into.
- [Troubleshooting](/guides/troubleshooting) — what goes wrong and how to fix it.
- [Configuration](/guides/configuration) — every config key in full.
