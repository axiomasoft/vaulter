---
title: Configuration
description: A section-by-section walkthrough of config/vaulter.php — models, table names, id strategy, panels, features, permission drivers, CAS, sharing, uploads, webhooks and collaboration.
outline: deep
---

# Configuration

Publish the config with:

```bash
php artisan vendor:publish --tag=vaulter-config
```

It lands at `config/vaulter.php`. This page walks every section. The config is **cache-safe**
— every swappable binding is a fully-qualified class name (FQCN) string, never a closure — so
`php artisan config:cache` works.

::: tip Looking for a single key?
This page is the narrative walkthrough. For an exhaustive, alphabetical lookup table see the
[config-key reference](/reference/configuration).
:::

## Models

Every Vaulter entity is resolved through this map, so you can point any of them at your own
subclass (to add ULID keys, tenant scoping, audit behaviour, …):

```php
'models' => [
    // Legacy DocDrive (document-id-attached path)
    'document'   => \Vaulter\Models\Documents\Document::class,
    'version'    => \Vaulter\Models\Documents\DocumentVersion::class,
    'folder'     => \Vaulter\Models\Documents\DocumentFolder::class,
    'permission' => \Vaulter\Models\Documents\DocumentPermission::class,
    'comment'    => \Vaulter\Models\Documents\DocumentComment::class,
    'share'      => \Vaulter\Models\Documents\DocumentShare::class,

    // Vaulter Drive
    'storage'    => \Vaulter\Models\Drive\Storage::class,
    'node'       => \Vaulter\Models\Drive\Node::class,
    'file'       => \Vaulter\Models\Drive\File::class,
    'blob'       => \Vaulter\Models\Cas\Blob::class,
    'grant'      => \Vaulter\Models\Access\NodeGrant::class,
    'node_share' => \Vaulter\Models\Sharing\NodeShare::class,
    // …and file_version, file_version_blob, node_document_version,
    //    upload_session, upload_part, activity, outbox, webhook_endpoint,
    //    webhook_delivery
],
```

Vaulter route-binds `{storage}` and `{node}` against whatever you put here, so a host subclass
keeps resolving. See [Multi-tenancy](/guide/multi-tenancy) for the tenant-aware-model pattern.

## Table names & prefix

Every table is named `{table_prefix}{base}`. Override the prefix globally, or rename a single
entity's table in `table_names`:

```php
'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'), // → v_storages, v_nodes, …
'table_names' => [
    'storages' => 'storages',
    'nodes'    => 'nodes',
    'blobs'    => 'blobs',
    // …one base name per entity
],
```

So `storages` with prefix `v_` becomes the table `v_storages`.

## Primary key strategy (`ids`)

The id strategy decides the column type of every primary/morph key. **Default is `ulid`** so
morph keys line up with the Corex/AzGuard ecosystem.

```php
'ids' => [
    'default' => env('VAULTER_ID_STRATEGY', 'ulid'), // ulid | uuid7 | bigint
    'strategies' => [
        'bigint' => \Vaulter\Schema\AutoIncrementIds::class,
        'ulid'   => \Vaulter\Schema\UlidIds::class,
        'uuid7'  => \Vaulter\Schema\Uuid7Ids::class,
    ],
],
```

| Strategy | Column | Use when |
|---|---|---|
| `ulid` | 26-char ULID string | **Default.** Sortable, ecosystem-aligned morphs. |
| `uuid7` | native Postgres `uuid` | You want native uuid columns. |
| `bigint` | auto-increment big integer | Legacy / integer-key installs. |

::: warning Legacy `id_type`
The old binary `id_type` (`'id'` / `'ulid'`) key still exists for backward compatibility.
When `ids.default` is set, **it wins**; otherwise the strategy is derived from `id_type`
(`id → bigint`, `ulid → ulid`) so existing installs keep working. New installs should use
`ids.default`. See [ADR-0011](/adr/0011-id-strategy).
:::

## Storage Panels

A panel is a named config-envelope bound to a host entity over the shared `v_*` schema, with
**zero migrations** by default. List your provider FQCNs here:

```php
'panels' => [
    \App\Vaulter\Panels\ProjectDrivePanel::class,
],
'default_panel' => \Vaulter\Panels\DefaultStoragePanel::class,

'migrations' => [
    'path' => null, // where vaulter:panel:migrations writes own-tables panel migrations
],
```

Scaffold one with `php artisan vaulter:make-panel <name>`. Deep dive:
[Storage Panels](/guide/storage-panels).

## Features

Install-wide feature flags (a panel may override any of them per-panel):

```php
'features' => [
    'blobs'             => true,
    'versions'          => true,
    'grants'            => true,
    'activity'          => true,
    'uploads'           => true,
    'share_links'       => false,
    'document_versions' => false, // gates v_node_document_versions + document-node API
    'spreadsheets'      => false, // gates the xlsx UniverHandler
    'public_listing'    => false,
    'reliability'       => true,  // gates the outbox + webhook schema and routes
],
```

A flag gates **both** the relevant schema and the routes/subscribers, so turning one off keeps
the migration and the endpoints out of the app entirely.

## Document handlers

The registry that routes a node to the right format handler (mime → extension → capability →
priority), falling back to download-only. Add a format with one class + one line here:

```php
'handlers' => [
    \Vaulter\Documents\Handlers\TipTapHandler::class,
    \Vaulter\Documents\Handlers\PreviewHandler::class,
    \Vaulter\Documents\Handlers\DownloadOnlyHandler::class,
],
```

See [Document types](/guide/document-handlers) and the [custom-handler recipe](/guide/recipes).

## Authorization drivers

The Drive ACL combines the Vaulter-owned `AccessResolver` with a **swappable** host-role
driver. The default `database` driver is fully standalone (zero host dependency):

```php
'drivers' => [
    'permission' => env('VAULTER_PERMISSION_DRIVER', 'database'),
],

'permission_drivers' => [
    'database' => \Vaulter\Drivers\Permission\DatabasePermissionResolver::class,
    'gate'     => \Vaulter\Drivers\Permission\GatePermissionResolver::class,
    'spatie'   => \Vaulter\Drivers\Permission\SpatiePermissionResolver::class,
    'azgard'   => \Vaulter\Drivers\Permission\AzgardPermissionResolver::class,
    'null'     => \Vaulter\Testing\NullRoleDriver::class, // non-prod only
],
```

Each non-database driver has its own sub-block mapping a Vaulter grant level / ability to a
host permission name (`gate.*`, `spatie.*`, `azgard.*`). The full per-driver config is in
[Host-role driver](/guide/permission-drivers).

::: danger Null driver
`null` is an open driver selectable **only** outside production. Config validation rejects a
panel bound to it in production — bind a real driver before deploying.
:::

There is also a separate **legacy** document-path resolver:

```php
'permission_resolver' => null, // FQCN of a Contracts\Access\PermissionResolver, or null
```

## Content-addressed storage (CAS)

The swappable blob pipeline, the deduplication scope, the GC grace window and the default
disk:

```php
'cas' => [
    'content_store'  => \Vaulter\Cas\Sha256ContentStore::class,
    'storage_driver' => \Vaulter\Cas\FlysystemStorageDriver::class,
    'blob_store'     => \Vaulter\Cas\BlobStore::class,
],

'dedup' => [
    'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // global | tenant | storage
],

'gc' => [
    'grace_hours' => env('VAULTER_GC_GRACE_HOURS', 24), // floored at 1h
],

'storage' => [
    'default_disk' => env('VAULTER_DISK', 'local'),
],
```

::: warning Unversioned bucket
The CAS bucket must be **unversioned** — never use S3 lifecycle/versioning as a blob-deletion
mechanism; that fights the two-phase GC. See [Concepts → Blobs](/guide/storage-and-blobs) and the
[blob-GC recipe](/guide/recipes).
:::

## Sharing

Bearer share-links are a separate public channel; only the `sha256` hash of a token is stored:

```php
'share_token_generator' => \Vaulter\Support\RandomShareTokenGenerator::class,

'share' => [
    'token_generator' => \Vaulter\Support\RandomShareTokenGenerator::class,
    'url_base'        => env('VAULTER_SHARE_URL_BASE', '/share'),
    'prefix_length'   => 8,
],
```

Both channels (bearer node links and legacy document shares) are covered in
[Sharing](/guide/sharing); the two-channel design is [ADR-0013](/adr/0013-two-sharing-channels).

## Trash retention

The window before a trashed node's subtree is permanently purged by
`php artisan vaulter:purge-trash`:

```php
'trash' => [
    'retention_days' => env('VAULTER_TRASH_RETENTION_DAYS', 30),
],
```

## Resumable uploads

Large files upload resumably (tus on any disk; S3-multipart on object stores), then assemble
through the same race-free `BlobStore.commit`:

```php
'uploads' => [
    'max_bytes'          => env('VAULTER_UPLOAD_MAX_BYTES', 5_368_709_120), // 5 GiB
    'chunk_size'         => env('VAULTER_UPLOAD_CHUNK_SIZE', 8_388_608),    // 8 MiB
    'session_ttl_hours'  => env('VAULTER_UPLOAD_SESSION_TTL_HOURS', 24),
    'tus' => ['enabled' => env('VAULTER_UPLOAD_TUS_ENABLED', true)],
],
```

The `uploads` feature flag gates the schema **and** the route group. Details in
[Uploads](/guide/uploads).

## Reliability — outbox & webhooks

A transactional outbox stages each domain event, then a relay fans it out to signed webhook
deliveries (at-least-once with backoff + DLQ):

```php
'webhooks' => [
    'timeout'                => 5,
    'max_attempts'           => 5,
    'backoff'                => [10, 30, 60, 300, 1800],
    'disable_after_failures' => 10,
    'signature_header'       => 'X-Vaulter-Signature',
    'timestamp_header'       => 'X-Vaulter-Timestamp',
    'event_header'           => 'X-Vaulter-Event',
    'retention_days'         => 30,  // age past which vaulter:prune-webhooks reclaims SETTLED audit rows
    'manage_ability'         => env('VAULTER_WEBHOOKS_MANAGE_ABILITY', 'vaulter.webhooks.manage'),
],
```

Gated by the `reliability` feature flag. `retention_days` (floored at 0) is the window
`vaulter:prune-webhooks` uses to delete *settled* history — dispatched outbox rows and
terminal (`delivered`/`dead`) deliveries — while never touching in-flight rows. See the
[reliability & webhooks recipe](/guide/recipes).

## Documents & versions

```php
'max_versions'           => 50, // legacy document version cap (0 = unlimited)
'enabled_document_types' => ['richtext', 'table', 'markdown', 'template'],
'max_document_size_kb'   => 5120,

'versions' => [ // node-attached document versions (M4a)
    'max'                       => 50,
    'autosave_debounce_seconds' => 30,
],

'drive' => [ // binary Drive upload policy (kept apart from the document limit)
    'max_upload_kb' => null,  // null = falls back to max_document_size_kb
    'allowed_mime'  => [],    // empty = permissive
],
```

## Export

Map a format to a `DocumentExporter` (add PDF/DOCX/your own):

```php
'exporters' => [
    'html'     => \Vaulter\Export\HtmlExporter::class,
    'markdown' => \Vaulter\Export\MarkdownExporter::class,
],
```

See [Export](/guide/export) and the [custom-export recipe](/guide/recipes).

## Routes

```php
'route_prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
'middleware'   => ['api'],
```

## Collaboration

Yjs is the source of truth; the transport is swappable behind `driver`:

```php
'collaboration' => [
    'driver'         => env('VAULTER_COLLAB_DRIVER', 'reverb'), // reverb | hocuspocus
    'channel_prefix' => 'vaulter.documents',
    'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
    'drivers' => [
        'reverb' => [
            'class'      => \Vaulter\Collaboration\ReverbCollaborationDriver::class,
            'connection' => env('VAULTER_COLLAB_CONNECTION', 'reverb'),
        ],
        'hocuspocus' => [
            'class'     => \Vaulter\Collaboration\HocuspocusCollaborationDriver::class,
            'url'       => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
            'app_id'    => env('VAULTER_COLLAB_APP_ID'),
            'secret'    => env('VAULTER_COLLAB_SECRET'),
            'token_ttl' => 3600,
        ],
    ],
],
```

- **`reverb`** (default) — Yjs over Laravel Reverb, no extra service; the Vue client joins the
  private broadcast channel `{channel_prefix}.{nodeId}`, gated by the `AccessResolver`.
- **`hocuspocus`** — an external Node hub; the client connects with a signed room JWT and the
  hub calls back into the collab webhooks.

Full setup in [Collaboration → overview](/guide/collaboration) and
[server](/guide/collaboration). The decision is [ADR-0001](/adr/0001-collaboration-transport).

## Tenancy

When enabled, migrations add a tenant column. Vaulter does **not** scope by it for you —
supply a tenant-aware model:

```php
'tenant' => [
    'enabled' => env('VAULTER_TENANT_ENABLED', false),
    'column'  => 'tenant_id',
],
```

See [Multi-tenancy](/guide/multi-tenancy).
