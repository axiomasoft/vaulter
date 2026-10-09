---
title: Configuration
description: Package-owned configuration, typed accessors and migration from published 0.2 keys.
outline: deep
---

# Configuration

Each installed package owns one config file and one typed configuration API (AD26).
Publish only the files you need; for example:

```bash
php artisan vendor:publish --tag=vaulter-config
php artisan vendor:publish --tag=vaulter-documents-config
```

| Package | Published file | Publish tag | Configuration class |
|---|---|---|---|
| Core | `config/vaulter.php` | `vaulter-config` | `Vaulter\Configuration\VaulterConfig` |
| Media | `config/vaulter-media.php` | `vaulter-media-config` | `Vaulter\Media\Configuration\MediaConfig` |
| Drive | `config/vaulter-drive.php` | `vaulter-drive-config` | `Vaulter\Drive\Configuration\DriveConfig` |
| Documents | `config/vaulter-documents.php` | `vaulter-documents-config` | `Vaulter\Documents\Configuration\DocumentsConfig` |
| AzGuard | `config/vaulter-azgard.php` | `vaulter-azgard-config` | `Vaulter\Azgard\Configuration\AzgardConfig` |
| Filament | `config/vaulter-filament.php` | `vaulter-filament-config` | `Vaulter\Filament\Configuration\FilamentConfig` |

All bindings use FQCN strings. Closures are rejected at boot, so the files remain safe
for `php artisan config:cache`. See the [key reference](/reference/configuration) for defaults.

## Core: database, IDs, profiles and access

Only core owns schema settings: `database.connection`, `database.table_prefix`,
`ids.strategy`, `ids.host_keys` and `tenancy.enabled`. A null connection uses Laravel's
default connection. Tables use the prefix plus their canonical entity name:
`DatabaseConfig::table('drives')` returns `v_drives` by default. `table_names` is an
upgrade input only, read by `DatabaseConfig::legacyTable()` to locate old physical tables.

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
'profiles' => [
    'default' => \Vaulter\Profiles\DefaultProfile::class,
    'providers' => [\App\Vaulter\Profiles\ProjectProfile::class],
],
'features' => [
    'grants' => true,
    'activity' => true,
    'webhooks' => true,
    'share_links' => false,
    'public_listing' => false,
],
'access' => [
    'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
    'permission_drivers' => [
        'database' => \Vaulter\Access\Drivers\DatabasePermissionDriver::class,
        'gate' => \Vaulter\Access\Drivers\GatePermissionDriver::class,
        'spatie' => \Vaulter\Access\Drivers\SpatiePermissionDriver::class,
        'null' => \Vaulter\Testing\NullPermissionDriver::class,
    ],
    // gate.* and spatie.* retain their ability/permission maps here.
],
'handlers' => [],
```

### Host keys (`ids.host_keys`) {#host-keys-ids-host-keys}

`ids.host_keys = string` emits `varchar(64)` independently of Vaulter's entity IDs.
Narrow it to bigint/uuid/ulid only when all host-owned keys fit that type. Changing schema
settings after migration requires a schema upgrade; see UPGRADE.md.

Set `tenancy.enabled` to opt into your host's tenancy setup. The column name is always
`tenant_id`; supply tenant-aware model subclasses to apply your host's scope.
See [Multi-tenancy](/guide/multi-tenancy).

`profiles.providers` and `profiles.default` contain `ProfileProvider` FQCNs. Scaffold one
with `php artisan vaulter:make:profile project`. Profile keys must be unique and the
default must be registered. See [Drive Profiles](/guide/storage-panels).

### Models {#models}

Core model overrides are `models.drive`, `node`, `file`, `file_revision`,
`file_revision_blob`, `blob`, `grant`, `node_share`, `activity`, `outbox`,
`webhook_endpoint` and `webhook_delivery`; each must extend the matching built-in model.
Drive and Documents models live under their own config roots.

The standalone `database` permission driver is the default. Gate and Spatie maps now
live at `access.gate.*` and `access.spatie.*`. The installed AzGuard bridge registers
`access.permission_drivers.azgard`; its own settings remain in `vaulter-azgard.*`.
The open `null` driver is rejected in production. See [Permission drivers](/guide/permission-drivers).

`handlers` contains additional `FileHandler` FQCNs. Providers register built-in handlers
through the `vaulter.file_handlers` container tag, so an empty list retains preview/download
and the installed Documents handlers.

## Routes and sharing {#routes}

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
'sharing' => [
    'token_generator' => \Vaulter\Support\RandomShareTokenGenerator::class,
    'url_base' => '/share',
    'prefix_length' => 8,
],
```

The authenticated stack protects actor routes; public share links use `public`, and signed
collaboration callbacks use `hub`. Request metrics are appended automatically. The old
`middleware` setting affects only the authenticated stack; configure public middleware
explicitly. Install Sanctum or replace `auth:sanctum` with your host's guard.

`features.webhooks` controls webhook runtime behavior. Existing delivery settings remain
under `webhooks.*`, including the URL guard, timeout, retry/backoff and retention controls.
`listing.scan_budget`, `downloads.inline_mime_types` and `observability.*` remain core-owned.

## Media: content-addressed storage

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
are floored at one hour. The canonical dedup scope is `drive` (formerly `storage`).
`global` was removed (PLAN2.D49): the provider refuses it at boot with
`InvalidConfigurationException`; run `vaulter:blobs:repair-scopes` to move existing global blobs.
Deduplication uses the stored `scope_key`; changing the scope leaves existing blobs valid
and affects new uploads. See [Storage & blobs](/guide/storage-and-blobs).

## Drive: uploads, names and trash

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
[Drive → Names, limits, stars and properties](/guide/drive)); `vaulter-drive.names.max_length`,
`vaulter.drive.name_rules.max_length` and `vaulter.extra_data.enabled` are deprecated and
ignored (`E_USER_DEPRECATED`). See [Uploads](/guide/uploads) and [Trash](/guide/trash).

## Documents: content, revisions, comments and collaboration

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
revisions are always enabled; `features.blobs`, `features.versions` and
`features.document_versions` are removed. Old `false` values warn and are ignored.
Comments now default to **true**; set `vaulter-documents.comments.enabled = false`
to disable them. A published old `features.comments = false` is migrated and stays false.
Collaboration remains opt-in. When enabled, both hub secrets must be present and distinct:
production boot fails otherwise; development and testing log a warning.

`exporters` is a list of exporter class names (the 0.2 format → FQCN map is still read, with a deprecation). Custom TipTap allow-lists move to
`vaulter-documents.tiptap.*`. `vaulter.enabled_document_types` and
`vaulter.features.spreadsheets` become the list of [document type](/guide/document-types)
classes `vaulter-documents.types` (`richtext`/`table`/`template` → `RichTextDocumentType`,
`markdown` → `MarkdownDocumentType`, `spreadsheets = true` → `SpreadsheetDocumentType`), with a
deprecation. A list without a rich-text format (`['markdown']`) leaves the default
`vaulter-documents.default_type` (`vaulter/rich-text`) unregistered, and boot fails until you set it
to a registered key such as `vaulter/markdown`.
See [Collaboration](/guide/collaboration) and [Export](/guide/export).

## Typed access

Configuration classes are `final readonly` singletons over
`Illuminate\Contracts\Config\Repository`. Inject them into services. In models,
migrations and static contexts, resolve them with `app(...)`.

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

`VaulterConfig` exposes `database()`, `ids()`, `tenancy()`, `access()`, `routes()`,
`listing()`, `sharing()`, `downloads()`, `webhooks()` and `observability()`, plus
`model(string)`, `featureEnabled(string)`, `profileProviders()`, `defaultProfile()`,
`handlers()` and `extraDataEnabled()`. Module classes expose their own models and settings.
Values are read on every call: changing the repository with `config()->set()` in tests is
visible immediately. Package code reads Vaulter keys only within `Configuration` classes;
architecture checks enforce this boundary.

## Migrating published 0.2 configuration

Before providers boot, `ConfigNormalizer` translates old keys for installed packages.
Each deprecated key emits one `E_USER_DEPRECATED`; repeated normalization does not
repeat warnings. If an explicitly customized canonical value differs from the translated
legacy value, boot throws `Vaulter\Exceptions\Configuration\InvalidConfigurationException`.
Equal values emit only the deprecation. Legacy keys for absent modules are left untouched.
Support for deprecated keys ends at 1.0.

| Deprecated key | Canonical key |
|---|---|
| `vaulter.connection` | `vaulter.database.connection` |
| `vaulter.table_prefix` | `vaulter.database.table_prefix` |
| `vaulter.id_type / vaulter.ids.default` | `vaulter.ids.strategy` |
| `vaulter.tenant.enabled` | `vaulter.tenancy.enabled` |
| `vaulter.panels / vaulter.default_panel` | `vaulter.profiles.providers / vaulter.profiles.default` |
| `vaulter.features.reliability` | `vaulter.features.webhooks` |
| `vaulter.drivers.permission` | `vaulter.access.permission_driver` |
| `vaulter.permission_drivers` | `vaulter.access.permission_drivers` |
| `vaulter.gate.* / vaulter.spatie.*` | `vaulter.access.gate.* / vaulter.access.spatie.*` |
| `vaulter.azgard.*` | `vaulter-azgard.*` |
| `vaulter.route_prefix` | `vaulter.routes.prefix` |
| `vaulter.middleware` | `vaulter.routes.middleware.authenticated` |
| `vaulter.rate_limits.{public,uploads}.per_minute` | `vaulter.routes.rate_limits.{public,uploads}` |
| `vaulter.share_token_generator / vaulter.share.token_generator` | `vaulter.sharing.token_generator` |
| `vaulter.share.{url_base,prefix_length,default_ttl_hours,max_ttl_hours}` | `vaulter.sharing.{url_base,prefix_length,default_ttl_hours,max_ttl_hours}` |
| `vaulter.downloads.inline_mime_whitelist` | `vaulter.downloads.inline_mime_types` |
| `vaulter.storage.default_disk` | `vaulter-media.disk` |
| `vaulter.dedup.scope` | `vaulter-media.dedup.scope` |
| `vaulter.gc.*` | `vaulter-media.gc.*` |
| `vaulter.cas.{content_store,storage_driver,blob_store}` | `vaulter-media.{content_store,storage_driver,blob_store}` |
| `vaulter.uploads.max_bytes / vaulter.drive.max_upload_kb` | `vaulter-drive.uploads.max_file_size` |
| `vaulter.drive.allowed_mime` | `vaulter-drive.uploads.allowed_mime_types` |
| `vaulter.uploads.chunk_size` | `vaulter-drive.uploads.resumable.chunk_size` |
| `vaulter.uploads.max_chunk_bytes` | `vaulter-drive.uploads.resumable.max_chunk_size` |
| `vaulter.uploads.session_ttl_hours` | `vaulter-drive.uploads.resumable.session_ttl_hours` |
| `vaulter.uploads.max_active_sessions` | `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` |
| `vaulter.uploads.metadata.*` | `vaulter-drive.uploads.resumable.metadata.*` |
| `vaulter.features.uploads / vaulter.uploads.tus.enabled` | `vaulter-drive.uploads.resumable.enabled` |
| `vaulter.trash.retention_days` | `vaulter-drive.trash.retention_days` |
| `vaulter.max_document_size_kb` | `vaulter-documents.content.max_size_kb` |
| `vaulter.max_versions / vaulter.versions.max` | `vaulter-documents.revisions.limit` |
| `vaulter.features.comments` | `vaulter-documents.comments.enabled` |
| `vaulter.features.collaboration` | `vaulter-documents.collaboration.enabled` |
| `vaulter.collaboration.driver` | `vaulter-documents.collaboration.driver` |
| `vaulter.collaboration.channel_prefix` | `vaulter-documents.collaboration.broadcast.channel_prefix` |
| `vaulter.collaboration.webhook_secret` | `vaulter-documents.collaboration.hub.webhook_secret` |
| `vaulter.collaboration.webhook_timestamp_tolerance` | `vaulter-documents.collaboration.hub.timestamp_tolerance` |
| `vaulter.collaboration.drivers.hocuspocus.secret` | `vaulter-documents.collaboration.hub.jwt_secret` |
| `vaulter.collaboration.drivers.hocuspocus.{url,token_ttl}` | `vaulter-documents.collaboration.hub.{url,token_ttl}` |
| `vaulter.tiptap.*` | `vaulter-documents.tiptap.*` |
| `vaulter.exporters` | `vaulter-documents.exporters` |
| `vaulter.models.storage` | `vaulter.models.drive` |
| `vaulter.models.file_version / vaulter.models.file_version_blob` | `vaulter.models.file_revision / vaulter.models.file_revision_blob` |
| `vaulter.models.upload_session / vaulter.models.upload_part` | `vaulter-drive.models.upload_session / vaulter-drive.models.upload_part` |
| `vaulter.models.node_comment` | `vaulter-documents.models.node_comment` |
| `vaulter.models.node_document_version` | `vaulter-documents.models.document_revision` |
| `vaulter-filament.resources.storage` | `vaulter-filament.resources.drive` |

`id_type = id` becomes `ids.strategy = bigint`; `ulid` stays `ulid`. An obsolete panel class
must be rewritten as a `ProfileProvider`; the error points to `vaulter:make:profile`.
The two upload limits are compared in bytes (`max_upload_kb × 1024`); different legacy
limits warn and use the smaller. A legacy `false` for either uploads or tus disables
`resumable.enabled`.

When both old ID keys are present, `ids.default` retains precedence over `id_type`.
For the old share-token aliases, a custom value wins over the other shipped default;
two different custom values throw `InvalidConfigurationException`. Keep one
`sharing.token_generator`.

`tenant.column`, `drive.name_rules.no_separators_pattern` and `migrations.path` are ignored
with deprecation notices; tenant columns and separator checks now have fixed definitions.
`versions.autosave_debounce_seconds` and `vaulter-documents.revisions.autosave_debounce_seconds`
are ignored the same way: document checkpoints are immutable and follow
`vaulter-documents.revisions.checkpoint_interval_seconds` instead (PLAN2.D34).
Keep `table_names` only while upgrading physical tables.

Migrate the old Media publication to `config/vaulter-media.php`. Normalization translates
exact shipped built-in class-name strings on legacy keys, and preserves custom FQCNs.
PHP imports and custom class declarations must use canonical names; there are no PHP aliases.
See [UPGRADE.md](https://github.com/axioma-studio/vaulter/blob/main/UPGRADE.md).
