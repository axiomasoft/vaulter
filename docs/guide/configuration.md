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
    // Vaulter Drive entities
    'storage'    => \Vaulter\Models\Drive\Drive::class,
    'node'       => \Vaulter\Models\Drive\Node::class,
    'file'       => \Vaulter\Models\Drive\File::class,
    'blob'       => \Vaulter\Models\Cas\Blob::class,
    'grant'      => \Vaulter\Models\Access\NodeGrant::class,
    'node_share' => \Vaulter\Models\Sharing\NodeShare::class,
    // …and file_version, file_version_blob, activity, outbox, webhook_endpoint,
    //    webhook_delivery
],
```

::: info Drive/Documents-owned models
`upload_session` / `upload_part` are owned by `axioma-studio/vaulter-drive`; the
node-attached `node_comment` / `node_document_version` models are owned by
`axioma-studio/vaulter-documents`. Neither ships a default here — each package merges
its own entry via its own `config/vaulter-drive.php` / `config/vaulter-documents.php`
and throws if you resolve it without the package installed. The legacy
document-id-attached models (`document`, `version`, `folder`, `permission`, `comment`,
`share`) were fully excised — see [ADR-0014](/adr/0014-legacy-docvault-strangler).
:::

Vaulter route-binds `{storage}` and `{node}` against whatever you put here, so a host subclass
keeps resolving. See [Multi-tenancy](/guide/multi-tenancy) for the tenant-aware-model pattern.

## Table names & prefix

Every table is named `{table_prefix}{canonical_entity}`. Configure a global prefix:

```php
'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'), // v_drives, v_nodes, …
```

`Config::table('drives')` returns `v_drives`. Per-entity `table_names` overrides
are legacy upgrade inputs only: `Config::legacyTable()` reads them while migrations
normalize old physical names. Fresh installs ignore them. All core tables exist
regardless of feature flags; runtime routes and behavior still honor those flags.
Back up before upgrading; see [UPGRADE.md](https://github.com/axiomasoft/vaulter/blob/main/UPGRADE.md).

## Primary key strategy (`ids`)

The id strategy decides the column type of Vaulter primary keys and references.
**Default is `ulid`**. Keys owned by the host use the separate `ids.host_keys` setting (AD25).

```php
'ids' => [
    'default' => env('VAULTER_ID_STRATEGY', 'ulid'), // ulid | uuid7 | bigint
    'host_keys' => env('VAULTER_HOST_KEYS', 'string'), // string | bigint | uuid | ulid
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

### Host keys (`ids.host_keys`)

`string` is the default and emits `varchar(64)`. It accepts UUID users, bigint projects,
ULID actors and bigint Spatie roles together, independently of Vaulter's own ID strategy.
The setting applies to storage owners, grant subjects, comment authors, every `tenant_id`
and actor columns such as `created_by`, `granted_by` and `activity.actor_id`.

| Host-key setting | PostgreSQL | MySQL / MariaDB |
|---|---|---|
| `string` | `varchar(64)` | `varchar(64)` |
| `bigint` | `bigint` | `bigint unsigned` |
| `uuid` | `uuid` | `char(36)` |
| `ulid` | `char(26)` | `char(26)` |

Choose a narrower type only when every host key fits it. Unknown settings throw
`InvalidArgumentException`. Host migrations can use `VaulterSchema::hostKey($table, 'owner_id')`;
`actor()` and `tenant()` also use this setting. `key()`, `reference()` and `morphs()`
continue to use Vaulter's entity-ID strategy. Existing installations run upgrade migration
`2026_10_01_000003_host_key_columns`; see `UPGRADE.md`.

## Storage Panels

A panel is a named config-envelope bound to a host entity over the shared `v_*` schema, with
**zero migrations** by default. List your provider FQCNs here:

```php
'panels' => [
    \App\Vaulter\Profiles\ProjectDriveProfile::class,
],
'default_panel' => \Vaulter\Profiles\DefaultProfile::class,

'migrations' => [
    'path' => null, // legacy key; no longer used by profile scaffolding
],
```

Scaffold one with `php artisan vaulter:make:profile <name>`. Deep dive:
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
    \Vaulter\Documents\Engines\Handlers\TipTapHandler::class,
    \Vaulter\Handlers\Builtin\PreviewHandler::class,
    \Vaulter\Handlers\Builtin\DownloadOnlyHandler::class,
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
    'database' => \Vaulter\Access\Drivers\DatabasePermissionDriver::class,
    'gate'     => \Vaulter\Access\Drivers\GatePermissionDriver::class,
    'spatie'   => \Vaulter\Access\Drivers\SpatiePermissionDriver::class,
    'azgard'   => \Vaulter\Access\Drivers\AzgardPermissionDriver::class,
    'null'     => \Vaulter\Testing\NullPermissionDriver::class, // non-prod only
],
```

Each non-database driver has its own sub-block mapping a Vaulter grant level / ability to a
host permission name (`gate.*`, `spatie.*`, `azgard.*`). The full per-driver config is in
[Host-role driver](/guide/permission-drivers).

::: danger Null driver
`null` is an open driver selectable **only** outside production. Config validation rejects a
panel bound to it in production — bind a real driver before deploying. If you just need a
working smoke-test default, the standalone `database` driver above is the intended path —
note it is fail-closed (an empty `v_node_grants` means no access, not open access), so seed
grants or pick a driver whose host roles broaden access before assuming "it's not working".
:::

## Content-addressed storage (CAS)

The swappable blob pipeline, the deduplication scope, the GC grace window and the default
disk:

```php
'cas' => [
    'content_store'  => \Vaulter\Media\Cas\Sha256ContentStore::class,
    'storage_driver' => \Vaulter\Media\Cas\FlysystemStorageDriver::class,
    'blob_store'     => \Vaulter\Media\Cas\BlobStore::class,
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

The `uploads` feature flag gates the route group; upload tables always exist. Details in
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
'routes' => [
    'middleware' => [
        'authenticated' => ['api', 'auth:sanctum'],
        'public' => ['api'],
        'hub' => ['api'],
    ],
],
```

Protected routes use the `authenticated` stack. Public `share/{token}*` routes use
`public` plus the token resolver; signed `collab/authenticate` and `collab/store`
callbacks use `hub`. Both public surfaces retain `throttle:vaulter-public`, and
resumable uploads retain `throttle:vaulter-uploads`. Request metrics are appended
once to every stack automatically.

Install Laravel Sanctum for the default `auth:sanctum` guard, or replace that entry
with your application's authentication middleware. The legacy `vaulter.middleware`
key overrides only `authenticated` when non-null, including an explicit empty list;
remove it to enable the new default. `Config::middleware()` is replaced by
`authenticatedMiddleware()`, `publicMiddleware()` and `hubMiddleware()` (AD31).
Resource resolution applies only to Vaulter routes; host `{node}` and `{storage}`
parameters keep their own bindings.

## Collaboration

Yjs is the source of truth; the transport is swappable behind `driver`:

```php
'collaboration' => [
    'driver'         => env('VAULTER_COLLAB_DRIVER', 'hocuspocus'), // hocuspocus
    'channel_prefix' => 'vaulter.documents',
    'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
    'drivers' => [
        'hocuspocus' => [
            'class'     => \Vaulter\Documents\Collaboration\HocuspocusCollaborationDriver::class,
            'url'       => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
            'app_id'    => env('VAULTER_COLLAB_APP_ID'),
            'secret'    => env('VAULTER_COLLAB_SECRET'),
            'token_ttl' => 3600,
        ],
    ],
],
```

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
