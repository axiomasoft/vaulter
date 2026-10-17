---
title: Configuration
description: Package-owned configuration and typed accessors.
outline: deep
---

# Configuration

Each installed package owns one config file and one typed configuration API (AD26).
Publish only the files you need; for example:

<!-- snippet: shell -->

```bash
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-documents-config
```

| Package | Published file | Publish tag | Configuration class |
|---|---|---|---|
| Core (kernel) | `config/vaulter.php` | `vaulter-config` | `Vaulter\Configuration\VaulterConfig` |
| Media | `config/vaulter-media.php` | `vaulter-media-config` | `Vaulter\Media\Configuration\MediaConfig` |
| Drive | `config/vaulter-drive.php` | `vaulter-drive-config` | `Vaulter\Drive\Configuration\DriveConfig` |
| Documents | `config/vaulter-documents.php` | `vaulter-documents-config` | `Vaulter\Documents\Configuration\DocumentsConfig` |
| Webhooks | `config/vaulter-webhooks.php` | `vaulter-webhooks-config` | `Vaulter\Webhooks\Configuration\WebhooksConfig` |
| AzGuard | `config/vaulter-azgard.php` | `vaulter-azgard-config` | `Vaulter\Azgard\Configuration\AzgardConfig` |
| Filament | `config/vaulter-filament.php` | `vaulter-filament-config` | `Vaulter\Filament\Configuration\FilamentConfig` |

All bindings use FQCN strings. Closures are rejected at boot, so the files remain safe
for `php artisan config:cache`. See the [key reference](/reference/config) for defaults.

## Kernel: database, IDs, tenancy, routes and the outbox

Only core owns schema settings: `database.connection`, `database.table_prefix`,
`ids.strategy`, `ids.host_keys` and `tenancy.enabled`. A null connection uses Laravel's
default connection. Tables use the prefix plus their canonical entity name:
`DatabaseConfig::table('drives')` returns `v_drives` by default. Every other root is owned by the
package whose concept it configures (see the table above and
[ADR-0030](/adr/0030-package-ownership-and-schema)): a key never lives in two files.

<!-- snippet: config -->

```php
// config/vaulter.php
'database' => [
    'connection' => env('VAULTER_DB_CONNECTION'),
    'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'),
],
'ids' => [
    'strategy' => env('VAULTER_ID_STRATEGY', 'ulid'), // bigint | ulid | uuid7
    'host_keys' => env('VAULTER_HOST_KEYS', 'string'), // string | bigint | uuid | ulid
    'strategies' => [
        'bigint' => \Vaulter\Schema\AutoIncrementIds::class,
        'ulid' => \Vaulter\Schema\UlidIds::class,
        'uuid7' => \Vaulter\Schema\Uuid7Ids::class,
    ],
],
'modules' => [],
'outbox' => ['batch' => 100, 'max_attempts' => 10],
```

### Host keys (`ids.host_keys`) {#host-keys-ids-host-keys}

`ids.host_keys = string` emits `varchar(64)` independently of Vaulter's entity IDs.
Narrow it to bigint/uuid/ulid only when all host-owned keys fit that type. Changing schema
settings after migration requires a schema upgrade; see UPGRADE.md.

Set `tenancy.enabled` to opt into your host's tenancy setup. The column name is always
`tenant_id`; supply tenant-aware model subclasses to apply your host's scope.
See [Multi-tenancy](/concepts/tenancy). `modules` switches modules on and off
([Modules](/concepts/modules)); `outbox.*` tunes the relay ([The outbox](/concepts/events-outbox)).

## Drive: profiles, features, access, handlers and models {#drive}

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'profiles' => [
    'default' => \Vaulter\Drive\Profiles\DefaultProfile::class,
    'providers' => [\App\Vaulter\Profiles\ProjectProfile::class],
],
'features' => [
    'grants' => true,
    'activity' => true,
    'share_links' => false,
    'public_listing' => false,
],
'access' => [
    'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
    'permission_drivers' => [
        'database' => \Vaulter\Drive\Access\Drivers\DatabasePermissionDriver::class,
        'gate' => \Vaulter\Drive\Access\Drivers\GatePermissionDriver::class,
        'spatie' => \Vaulter\Drive\Access\Drivers\SpatiePermissionDriver::class,
        'null' => \Vaulter\Drive\Testing\NullPermissionDriver::class,
    ],
    // gate.* and spatie.* retain their ability/permission maps here.
],
'handlers' => [],
```

`vaulter-drive.profiles.providers` and `vaulter-drive.profiles.default` contain `ProfileProvider` FQCNs. Scaffold one
with `php artisan vaulter:make:profile project`. Profile keys must be unique and the
default must be registered. See [Drive Profiles](/concepts/drives-and-profiles).

### Models {#models}

Each package keeps the overrides of the models it owns under its own root, and each must extend
the matching built-in model: `vaulter-drive.models.drive`, `node`, `file`, `file_revision`,
`file_revision_blob`, `grant`, `node_share`, `activity` and the scan and upload models;
`vaulter-media.models.blob`; `vaulter-documents.models.*`; `vaulter-webhooks.models.webhook_endpoint` and
`webhook_delivery`; and `vaulter.models.outbox` for the kernel.

The standalone `database` permission driver is the default. Gate and Spatie maps live at
`vaulter-drive.access.gate.*` and `vaulter-drive.access.spatie.*`. The installed AzGuard bridge registers
`vaulter-drive.access.permission_drivers.azgard`; its own settings remain in `vaulter-azgard.*`.
The open `null` driver is rejected in production. See [Permission drivers](/extending/permission-drivers).

`vaulter-drive.handlers` contains additional `FileHandler` FQCNs. Providers register built-in handlers
through the `vaulter.file_handlers` container tag, so an empty list retains preview/download
and the installed Documents handlers.

## Routes and sharing {#routes}

<!-- snippet: config -->

```php
// config/vaulter.php
'routes' => [
    'prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
    'middleware' => [
        'authenticated' => ['api', 'auth:sanctum'],
        'public' => ['api'],
        'hub' => ['api'],
    ],
    'rate_limits' => ['public' => 60, 'uploads' => 120],
],
```

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'sharing' => [
    'token_generator' => \Vaulter\Drive\Support\RandomShareTokenGenerator::class,
    'url_base' => '/share',
    'prefix_length' => 8,
],
```

The authenticated stack protects actor routes; public share links use `public`, and signed
collaboration callbacks use `hub`. Request metrics are appended automatically. The
`middleware` setting affects only the authenticated stack; configure public middleware
explicitly. Install Sanctum or replace `auth:sanctum` with your host's guard.

## Webhooks {#webhooks}

Webhooks are a separate package (`composer require axioma-studio/vaulter-webhooks`) and an opt-in
module: `vaulter.modules.webhooks` (default `false`) mounts their routes, commands, schedules and doctor checks
(see [Modules](/concepts/modules)). Delivery settings live in `config/vaulter-webhooks.php` (publish tag
`vaulter-webhooks-config`): the URL guard, timeouts, retry schedule and retention controls. See
[Webhooks](/guides/webhooks). `vaulter-drive.listing.scan_budget` and `vaulter-drive.downloads.inline_mime_types`
belong to the drive; `observability.*` to core.

## Media: content-addressed storage

<!-- snippet: config -->

```php
// config/vaulter-media.php
'disk' => env('VAULTER_DISK', 'local'),
'dedup' => ['scope' => env('VAULTER_DEDUP_SCOPE', 'tenant')], // tenant | drive | off
'gc' => ['grace_hours' => 24, 'orphan_grace_hours' => 24],
'content_store' => \Vaulter\Media\Cas\Sha256ContentStore::class,
'storage_driver' => \Vaulter\Media\Cas\FlysystemStorageDriver::class,
'blob_store' => \Vaulter\Media\Cas\BlobStore::class,
```

Use an unversioned CAS bucket; blob deletion is managed by two-phase GC. Grace windows
are floored at one hour. The dedup scope is `tenant`, `drive` or `off`.
`global` was removed (PLAN2.D49): the provider refuses it at boot with
`InvalidConfigurationException`.
Deduplication uses the stored `scope_key`; changing the scope leaves existing blobs valid
and affects new uploads. See [Storage & blobs](/concepts/storage-and-blobs).

## Drive: uploads, names and trash

<!-- snippet: config -->

```php
// config/vaulter-drive.php
'uploads' => [
    'max_file_size' => 5_368_709_120, // bytes; 0 = unlimited
    'allowed_mime_types' => [],
    'resumable' => [
        'enabled' => true,
        'chunk_size' => 8_388_608,
        'max_chunk_size' => 67_108_864,
        'session_ttl_hours' => 24,
        'max_active_sessions_per_actor' => 20,
        'metadata' => [
            'allowed_keys' => ['filename', 'filetype', 'name', 'mime_type'],
            'max_value_length' => 512,
        ],
    ],
],
'trash' => ['retention_days' => 30],
'models' => [
    'upload_session' => \Vaulter\Drive\Models\Uploads\UploadSession::class,
    'upload_part' => \Vaulter\Drive\Models\Uploads\UploadPart::class,
],
```

`max_file_size` is a byte limit shared by ordinary and resumable uploads. A zero chunk cap
or active-session cap disables that cap. `resumable.enabled` controls resumable endpoints;
upload tables exist independently of this setting. Name rules, folder limits and the
per-profile file size limit live in the profile's drive policy (see
[Drive → Names, limits, stars and properties](/concepts/nodes-and-revisions)). See [Uploads](/guides/uploads) and [Trash](/guides/trash).

## Documents: content, revisions, comments and collaboration

<!-- snippet: config -->

```php
// config/vaulter-documents.php
'content' => ['max_size_kb' => 5120],
'revisions' => ['limit' => 50, 'checkpoint_interval_seconds' => 300],
'comments' => ['enabled' => true],
'exporters' => [\Vaulter\Documents\Export\HtmlExporter::class, \Vaulter\Documents\Export\MarkdownExporter::class],
'collaboration' => [
    'enabled' => false,
    'driver' => 'hocuspocus',
    'hub' => [
        'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
        'jwt_secret' => env('VAULTER_COLLAB_SECRET'),
        'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
        'token_ttl' => 3600,
        'timestamp_tolerance' => 300,
    ],
    'broadcast' => ['channel_prefix' => 'vaulter.documents'],
],
'models' => [
    'document_revision' => \Vaulter\Documents\Models\Revisions\DocumentRevision::class,
    'node_comment' => \Vaulter\Documents\Models\Comments\NodeComment::class,
],
```

Installing `vaulter-documents` enables document editing and revisions. Blobs and file
revisions are always enabled.
Comments now default to **true**; set `vaulter-documents.comments.enabled = false`
to disable them. Collaboration remains opt-in. When enabled, both hub secrets must be present and distinct:
production boot fails otherwise; development and testing log a warning.

`exporters` is a list of exporter class names. Custom TipTap allow-lists live in
`vaulter-documents.tiptap.*`. `vaulter-documents.types` lists the registered
[document type](/extending/document-types) classes. A list without a rich-text type (`['markdown']`) leaves the default
`vaulter-documents.default_type` (`vaulter/rich-text`) unregistered, and boot fails until you set it
to a registered key such as `vaulter/markdown`.
See [Collaboration](/guides/collaboration) and [Export](/guides/export).

## Typed access

Configuration classes are `final readonly` singletons over
`Illuminate\Contracts\Config\Repository`. Inject them into services. In models,
migrations and static contexts, resolve them with `app(...)`.

<!-- snippet: host -->

```php
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Documents\Configuration\DocumentsConfig;

final readonly class ExportPolicy
{
    public function __construct(
        private VaulterConfig $config,
        private DocumentsConfig $documents,
    ) {}

    public function revisionLimit(): int
    {
        return $this->documents->revisionLimit();
    }
}

$database = app(VaulterConfig::class)->database();
$table = $database->table('drives');
$connection = $database->connection();
```

`VaulterConfig` (the kernel) exposes `database()`, `ids()`, `tenancy()`, `routes()`, `outbox()`,
`observability()` and `outboxModel()`. Everything else is read by the class of its owner: `DriveConfig` has
`access()`, `sharing()`, `listing()`, `downloads()`, `changes()`, `scan()`, `model(string)`,
`featureEnabled(string)`, `profileProviders()`, `defaultProfile()` and `handlers()`; `MediaConfig` the disk,
deduplication and GC; `WebhooksConfig` the timeouts, retries and URL guard; `DocumentsConfig` the documents.
Values are read on every call: changing the repository with `config()->set()` in tests is
visible immediately. Package code reads Vaulter keys only within `Configuration` classes;
architecture checks enforce this boundary.

## Keys that do not exist

Vaulter 1.0 reads only the keys of the published `config/vaulter*.php` files. Keys of earlier
development releases are not translated, deprecated or warned about; an unknown key is ignored. There is no
migration bridge (see [UPGRADE.md](https://github.com/axioma-studio/vaulter/blob/main/UPGRADE.md)).
Package defaults still fill every missing nested key of a partially published file, and a host-supplied
map or list (`ids.strategies`, `handlers`, `vaulter-documents.types`, the access drivers) replaces the default
one instead of being merged with it.
