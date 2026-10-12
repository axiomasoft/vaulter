---
title: Configuration reference
description: Exhaustive reference for every config/vaulter.php key — defaults, env vars, and accepted values.
outline: deep
---

# Configuration reference

Every key in `config/vaulter.php`, by section. Publish the file with:

```bash
php artisan vendor:publish --tag=vaulter-config
```

Each table column: the config path (relative to `vaulter.`), the env override (when
one exists), the shipped default, and what it controls. Values are read back through
the `Vaulter\Support\Config` helper, which the package uses everywhere instead of raw
`config()` calls.

::: warning Config-cache safe
Every binding is an FQCN or a plain scalar — no closures. The config is safe to
`php artisan config:cache`.
:::

## Models — `models.*`

Swappable Eloquent models. Point any entry at your own subclass to add behaviour
(ULID keys, tenant scoping, audit). Vaulter always resolves models through this map.

| Key | Default | Owned by |
|---|---|---|
| `models.storage` | `Vaulter\Models\Drive\Drive` | core |
| `models.node` | `Vaulter\Models\Drive\Node` | core |
| `models.file` | `Vaulter\Models\Drive\File` | core |
| `models.file_version` | `Vaulter\Models\Drive\FileRevision` | core |
| `models.blob` | `Vaulter\Models\Cas\Blob` | core |
| `models.file_version_blob` | `Vaulter\Models\Drive\FileRevisionBlob` | core |
| `models.grant` | `Vaulter\Models\Access\NodeGrant` | core |
| `models.node_share` | `Vaulter\Models\Sharing\NodeShare` | core |
| `models.upload_session` | `Vaulter\Drive\Models\Uploads\UploadSession` | `axioma-studio/vaulter-drive` |
| `models.upload_part` | `Vaulter\Drive\Models\Uploads\UploadPart` | `axioma-studio/vaulter-drive` |
| `models.node_comment` | `Vaulter\Documents\Models\Comments\NodeComment` | `axioma-studio/vaulter-documents` |
| `models.node_document_version` | `Vaulter\Documents\Models\Revisions\DocumentRevision` | `axioma-studio/vaulter-documents` |
| `models.activity` | `Vaulter\Models\Activity\Activity` | core |
| `models.outbox` | `Vaulter\Models\Webhooks\Outbox` | core |
| `models.webhook_endpoint` | `Vaulter\Models\Webhooks\WebhookEndpoint` | core |
| `models.webhook_delivery` | `Vaulter\Models\Webhooks\WebhookDelivery` | core |

::: info Package-owned models resolve only when the package is installed
`upload_session` / `upload_part` are merged into `vaulter.models` by
`axioma-studio/vaulter-drive`; `node_comment` / `node_document_version` are merged by
`axioma-studio/vaulter-documents`. Core ships **no default** for these four keys —
resolving them without the owning package installed throws. The legacy
document-id-attached models (`document`, `version`, `folder`, `permission`, `comment`,
`share`) were fully excised — see [ADR-0014](/adr/0014-legacy-docvault-strangler).
:::

## Table names — `table_names.*`

Base table name per entity, resolved as `{table_prefix}{base}`. Rename a single
entity's table here without changing the global prefix.

| Key | Default base |
|---|---|
| `table_names.storages` | `storages` |
| `table_names.nodes` | `nodes` |
| `table_names.blobs` | `blobs` |
| `table_names.files` | `files` |
| `table_names.file_versions` | `file_versions` |
| `table_names.file_version_blobs` | `file_version_blobs` |
| `table_names.node_grants` | `node_grants` |
| `table_names.node_shares` | `node_shares` |
| `table_names.node_comments` | `node_comments` |
| `table_names.node_document_versions` | `node_document_versions` |
| `table_names.upload_sessions` | `upload_sessions` |
| `table_names.upload_parts` | `upload_parts` |
| `table_names.activity` | `activity` |
| `table_names.outbox` | `outbox` |
| `table_names.webhook_endpoints` | `webhook_endpoints` |
| `table_names.webhook_deliveries` | `webhook_deliveries` |

The legacy document-id-attached tables (`documents`, `document_folders`,
`document_versions`, `document_permissions`, `document_comments`, `document_shares`)
were dropped — see [ADR-0014](/adr/0014-legacy-docvault-strangler).

## Primary keys — `id_type`, `ids.*`

| Key | Env | Default | Accepts |
|---|---|---|---|
| `id_type` | `VAULTER_ID_TYPE` | `id` | `id` \| `ulid` — **legacy**, superseded by `ids.default` |
| `ids.default` | `VAULTER_ID_STRATEGY` | `ulid` | `ulid` \| `uuid7` \| `bigint` |
| `ids.strategies.bigint` | — | `Vaulter\Schema\AutoIncrementIds` | an `IdStrategy` FQCN |
| `ids.strategies.ulid` | — | `Vaulter\Schema\UlidIds` | an `IdStrategy` FQCN |
| `ids.strategies.uuid7` | — | `Vaulter\Schema\Uuid7Ids` | an `IdStrategy` FQCN |

When `ids.default` is set it wins; otherwise the strategy is derived from `id_type`
(`id` → bigint, `ulid` → ulid). Register a custom strategy by adding a key under
`ids.strategies` pointing at an [`IdStrategy`](/reference/contracts#identity)
implementation.

## Storage panels — `panels`, `default_panel`, `migrations.*`

| Key | Default | Controls |
|---|---|---|
| `panels` | `[]` | A list of `ProfileProvider` FQCNs that override the default panel by id |
| `default_panel` | `Vaulter\Profiles\DefaultProfile` | The fallback panel FQCN |
| `migrations.path` | `null` | Legacy key retained for the configuration transition; unused by profile scaffolding. |

Scaffold a panel with `php artisan vaulter:make:profile <name>`. See
[Concepts → Storage panels](/guide/storage-panels).

## Features — `features.*`

Global feature toggles, read by `Config::featureEnabled()`; a panel may override any
of these per-panel.

| Key | Default | Gates |
|---|---|---|
| `features.blobs` | `true` | CAS blob storage |
| `features.versions` | `true` | File version history |
| `features.grants` | `true` | Per-node grants (the ACL) |
| `features.activity` | `true` | The activity log |
| `features.uploads` | `true` | The `v_upload_sessions`/`v_upload_parts` schema + resumable routes |
| `features.share_links` | `false` | Bearer node share links |
| `features.document_versions` | `false` | The `v_node_document_versions` schema + document-node Actions/HTTP (opt-in) |
| `features.spreadsheets` | `false` | The feature-flagged `UniverHandler` (xlsx editing) |
| `features.public_listing` | `false` | Public listing |
| `features.reliability` | `true` | The `v_outbox`/`v_webhook_*` schema + outbox subscriber + endpoint routes |

## Document handlers — `handlers`

A list of `FileHandler` FQCNs, resolved by the core-owned `Vaulter\Handlers\FileHandlerRegistry`
by mime → extension → capability → priority, falling back to the `DownloadOnlyHandler`
null-object. The two baseline entries are core's OWN default (VLT-CANON P0.4/D10 — resolved even
without `vaulter-documents` installed); `vaulter-documents` ADDS `TipTapHandler` via its own
config merge.

| Default entries | Owner |
|---|---|
| `Vaulter\Handlers\Builtin\PreviewHandler` | core |
| `Vaulter\Handlers\Builtin\DownloadOnlyHandler` | core |
| `Vaulter\Documents\Engines\Handlers\TipTapHandler` | vaulter-documents |

`UniverHandler` (xlsx) is appended by the service provider **only** when
`features.spreadsheets` is on. Add a new format with one class + an entry here (or
`Vaulter::handlers()->register()`).

## Tenancy — `tenant.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `tenant.enabled` | `VAULTER_TENANT_ENABLED` | `false` | When on, migrations add a tenant column. Vaulter does **not** scope by it — supply a tenant-aware model |
| `tenant.column` | — | `tenant_id` | The tenant column name |

See [Auth → Multi-tenancy](/guide/multi-tenancy).

## Table prefix — `table_prefix`

| Key | Env | Default |
|---|---|---|
| `table_prefix` | `VAULTER_TABLE_PREFIX` | `v_` |

Prefix for every Vaulter table (migrations and models read it), so the package
coexists with host tables.

## Authorization

### Legacy resolver — `permission_resolver`

| Key | Default | Controls |
|---|---|---|
| `permission_resolver` | `null` | FQCN of a `PermissionResolver`, or `null` to register one at runtime via `Vaulter::resolvePermissionsWith()`. **Legacy** document path |

### Drive ACL — `drivers.permission`, `permission_drivers.*`

| Key | Env | Default | Accepts |
|---|---|---|---|
| `drivers.permission` | `VAULTER_PERMISSION_DRIVER` | `database` | a key in `permission_drivers` |
| `permission_drivers.database` | — | `Vaulter\Access\Drivers\DatabasePermissionDriver` | standalone — authorises purely from grant rows |
| `permission_drivers.gate` | — | `Vaulter\Access\Drivers\GatePermissionDriver` | the Laravel Gate driver |
| `permission_drivers.spatie` | — | `Vaulter\Access\Drivers\SpatiePermissionDriver` | the spatie/laravel-permission driver |
| `permission_drivers.azgard` | — | `Vaulter\Azgard\AzgardPermissionDriver` | registered by the installed `vaulter-azgard` provider |
| `permission_drivers.null` | — | `Vaulter\Testing\NullPermissionDriver` | open, **non-production only** |

::: danger Null driver in production
The `null` driver is open and env-gated. Production config validation rejects a panel
bound to it — bind a real driver before deploying.
:::

### Gate driver map — `gate.*`

Used only when `drivers.permission = 'gate'`. Maps a `GrantLevel` to a host Gate
ability; the highest allowed level becomes the actor's role grant.

| Key | Env | Default |
|---|---|---|
| `gate.abilities.reader` | — | `vaulter.view` |
| `gate.abilities.commenter` | — | `vaulter.comment` |
| `gate.abilities.writer` | — | `vaulter.edit` |
| `gate.abilities.organizer` | — | `vaulter.organize` |
| `gate.abilities.owner` | — | `vaulter.manage` |
| `gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `vaulter.super` |
| `gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `vaulter.create` |

### Spatie driver map — `spatie.*`

Used only when `drivers.permission = 'spatie'` **and** spatie/laravel-permission is
installed.

| Key | Env | Default |
|---|---|---|
| `spatie.permissions.reader` | — | `vaulter.view` |
| `spatie.permissions.commenter` | — | `vaulter.comment` |
| `spatie.permissions.writer` | — | `vaulter.edit` |
| `spatie.permissions.organizer` | — | `vaulter.organize` |
| `spatie.permissions.owner` | — | `vaulter.manage` |
| `spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `vaulter.super` |
| `spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `vaulter.create` |
| `spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` |
| `spatie.role_subject_type` | — | `role` |
| `spatie.team_subject_type` | — | `team` |

`spatie.teams` falls back here only when the host's own `config('permission.teams')`
is unset. `role_subject_type`/`team_subject_type` are the morph aliases role/team
grant rows are addressed to.

### AzGuard driver map — `vaulter-azgard.*`

Separate package: `composer require axioma-studio/vaulter-azgard`. The settings below
come from `config/vaulter-azgard.php`, rather than core configuration.

| Key | Default |
|---|---|
| `vaulter-azgard.panel_id` | `null` (resolve the panel from the permission key) |

Used only when `drivers.permission = 'azgard'`. Action-centric — maps a Vaulter
`Ability` to an AzGuard permission key.

| Key | Default |
|---|---|
| `vaulter-azgard.permissions.view` | `documents.view` |
| `vaulter-azgard.permissions.download` | `documents.download` |
| `vaulter-azgard.permissions.comment` | `documents.comment` |
| `vaulter-azgard.permissions.edit` | `documents.update` |
| `vaulter-azgard.permissions.create` | `documents.create` |
| `vaulter-azgard.permissions.move` | `documents.update` |
| `vaulter-azgard.permissions.copy` | `documents.update` |
| `vaulter-azgard.permissions.trash` | `documents.delete` |
| `vaulter-azgard.permissions.delete` | `documents.delete` |
| `vaulter-azgard.permissions.restore` | `documents.delete` |
| `vaulter-azgard.permissions.share` | `documents.manage` |
| `vaulter-azgard.permissions.manage` | `documents.manage` |

| Key | Env | Default |
|---|---|---|
| `vaulter-azgard.create_permission` | `VAULTER_AZGARD_CREATE_PERMISSION` | `documents.create` |

The bridge supplies the real guard adapter. The host must implement `AzgardBackedUser`
and align ULID morphs — see [Permission drivers](/guide/permission-drivers).

## Storage adapter (legacy) — `storage_adapter`

| Key | Default |
|---|---|
| `storage_adapter` | `Vaulter\Support\DefaultStorageAdapter` |

Storage for legacy binary attachments — see [`StorageAdapter`](/reference/contracts#cas-content-addressed-storage).

## Content-addressed blob store — `cas.*`

The swappable CAS services, all bound in the provider and resolved through the
container.

| Key | Default | Contract |
|---|---|---|
| `cas.content_store` | `Vaulter\Media\Cas\Sha256ContentStore` | `ContentStore` |
| `cas.storage_driver` | `Vaulter\Media\Cas\FlysystemStorageDriver` | `StorageDriver` |
| `cas.blob_store` | `Vaulter\Media\Cas\BlobStore` | `BlobStore` |

## Deduplication — `dedup.scope`

| Key | Env | Default | Accepts |
|---|---|---|---|
| `dedup.scope` | `VAULTER_DEDUP_SCOPE` | `tenant` | `global` \| `tenant` \| `storage` |

The boundary the content address (sha256) is unique within. Drives both the
dedup-UNIQUE index and the scope-qualified storage key
`"<scope>/ab/cd/<sha256>"`. `global` = one physical object per sha256 install-wide;
`tenant` = unique per `(tenant_id, sha256)`; `storage` = unique per
`(storage_id, sha256)`.

## Blob GC — `gc.grace_hours`

| Key | Env | Default | Notes |
|---|---|---|---|
| `gc.grace_hours` | `VAULTER_GC_GRACE_HOURS` | `24` | Two-phase GC grace window; floored at 1h |
| `gc.orphan_grace_hours` | `VAULTER_ORPHAN_GRACE_HOURS` | falls back to `VAULTER_GC_GRACE_HOURS` (`24`) | Grace window for the orphan disk-walk (CAS reconciler) — a separate window from `gc.grace_hours`, so a just-promoted object isn't mistaken for orphaned bytes |

A blob whose ref-count cache reaches zero is tombstoned with
`delete_after_at = now + grace`; only after the grace passes are its bytes swept. See
[Recipes → Blob GC](/guide/recipes).

## CAS storage disk — `storage.default_disk`

| Key | Env | Default | Notes |
|---|---|---|---|
| `storage.default_disk` | `VAULTER_DISK` | `local` | The default Flysystem disk for canonical CAS objects when a blob's `v_storages.disk` doesn't pin its own |

::: warning Keep the CAS bucket unversioned
Never use S3 lifecycle/versioning as a blob-deletion mechanism.
:::

## Sharing

### Legacy token generator — `share_token_generator`

| Key | Default |
|---|---|
| `share_token_generator` | `Vaulter\Support\RandomShareTokenGenerator` |

### Bearer node share links — `share.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `share.token_generator` | — | `Vaulter\Support\RandomShareTokenGenerator` | The `ShareTokenGenerator` minting the raw bearer token (only its sha256 hash is stored) |
| `share.url_base` | `VAULTER_SHARE_URL_BASE` | `/share` | The public base a share URL is built from |
| `share.prefix_length` | — | `8` | How many leading chars of the raw token are stored as the non-secret `token_prefix` |

See [Usage → Sharing](/guide/sharing).

## Trash — `trash.retention_days`

| Key | Env | Default |
|---|---|---|
| `trash.retention_days` | `VAULTER_TRASH_RETENTION_DAYS` | `30` |

The retention window before a trashed node's subtree is permanently force-deleted by
`php artisan vaulter:purge-trash`.

## Resumable uploads — `uploads.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `uploads.max_bytes` | `VAULTER_UPLOAD_MAX_BYTES` | `5368709120` (5 GiB) | Hard ceiling on a session's declared total size; `0` = ∞ |
| `uploads.chunk_size` | `VAULTER_UPLOAD_CHUNK_SIZE` | `8388608` (8 MiB) | Advertised default chunk size (a hint) |
| `uploads.session_ttl_hours` | `VAULTER_UPLOAD_SESSION_TTL_HOURS` | `24` | A session not committed in this window is swept by `vaulter:sweep-uploads` |
| `uploads.tus.enabled` | `VAULTER_UPLOAD_TUS_ENABLED` | `true` | Gate the tus resumable endpoints (independent of the `uploads` schema flag) |

The `features.uploads` flag gates both the schema and the route group. See
[Usage → Uploads](/guide/uploads).

## Reliability — webhooks — `webhooks.*`

A transactional outbox + signed webhook delivery (at-least-once, exponential backoff,
DLQ).

| Key | Env | Default | Controls |
|---|---|---|---|
| `webhooks.timeout` | — | `5` | HTTP request timeout (seconds) per delivery POST |
| `webhooks.max_attempts` | — | `5` | Total attempts before a delivery is parked dead (DLQ) |
| `webhooks.backoff` | — | `[10, 30, 60, 300, 1800]` | Per-attempt retry delay (seconds); the tail caps further retries |
| `webhooks.disable_after_failures` | — | `10` | Consecutive dead deliveries that flip an endpoint `is_active` off |
| `webhooks.signature_header` | — | `X-Vaulter-Signature` | The HMAC-SHA256 signature header |
| `webhooks.timestamp_header` | — | `X-Vaulter-Timestamp` | The timestamp header (replay resistance) |
| `webhooks.event_header` | — | `X-Vaulter-Event` | The event-name header |
| `webhooks.retention_days` | — | `30` | Age past which dispatched outbox rows + terminal deliveries are prunable |
| `webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `vaulter.webhooks.manage` | The Gate ability gating the endpoint-management surface; `null` allows any authenticated user |

The `features.reliability` flag gates the schema, the outbox subscriber, and the
endpoint routes. See [Recipes → Reliability & webhooks](/guide/recipes).

## API routes — `route_prefix`, `middleware`

| Key | Env | Default |
|---|---|---|
| `route_prefix` | `VAULTER_ROUTE_PREFIX` | `api/vaulter` |
| `middleware` | — | `['api']` |

## Versioning (legacy) — `max_versions`

| Key | Default | Notes |
|---|---|---|
| `max_versions` | `50` | Max stored versions per **legacy** document (`0` = unlimited); oldest pruned |

## Document node versions — `versions.*`

| Key | Default | Controls |
|---|---|---|
| `versions.max` | `50` | Stored versions per node before pruning (`0` = unlimited); falls back to `max_versions` |
| `versions.autosave_debounce_seconds` | `30` | An autosave within this window of the head autosave version updates it in place (no new row) |

See [Usage → Versions](/guide/documents).

## Documents — `enabled_document_types`, `max_document_size_kb`

| Key | Default |
|---|---|
| `enabled_document_types` | `['richtext', 'table', 'markdown', 'template']` |
| `max_document_size_kb` | `5120` |

## Drive uploads (binary) — `drive.*`

| Key | Default | Controls |
|---|---|---|
| `drive.max_upload_kb` | `null` | Hard size ceiling (KB) for an uploaded file, enforced by `UploadFileRequest`. `null` → falls back to `max_document_size_kb` |
| `drive.allowed_mime` | `[]` | Optional mime allowlist. Empty = permissive (any mime). Listing mimes (e.g. `application/pdf`, `image/*`) restricts uploads via Laravel's `mimetypes` rule |

## Export formats — `exporters`

Map of format key → `DocumentExporter` FQCN.

| Key | Default |
|---|---|
| `exporters.html` | `Vaulter\Export\HtmlExporter` |
| `exporters.markdown` | `Vaulter\Export\MarkdownExporter` |

Add your own (PDF, DOCX, …) here or via `Vaulter::registerExporter()`. See
[Recipes → Custom export](/guide/recipes).

## Collaboration — `collaboration.*`

Yjs is the source of truth; TipTap JSON is the derived projection. The transport is
swappable behind the driver.

| Key | Env | Default | Controls |
|---|---|---|---|
| `collaboration.driver` | `VAULTER_COLLAB_DRIVER` | `hocuspocus` | `hocuspocus` |
| `collaboration.channel_prefix` | — | `vaulter.documents` | The room namespace; a room is always `prefix.nodeId` |
| `collaboration.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | `null` | Shared secret the Node hub presents on the S2S webhook endpoints. When null/empty the webhook surface accepts only a valid signed JWT |
| `collaboration.drivers.hocuspocus.url` | `VAULTER_COLLAB_URL` | `ws://localhost:1234` | The hub WebSocket URL |
| `collaboration.drivers.hocuspocus.app_id` | `VAULTER_COLLAB_APP_ID` | `null` | The hub app id |
| `collaboration.drivers.hocuspocus.secret` | `VAULTER_COLLAB_SECRET` | `null` | Room-JWT signing key **and** the key the webhooks verify a JWT against |
| `collaboration.drivers.hocuspocus.token_ttl` | — | `3600` | Room-JWT TTL (seconds) |

See [Collaboration → Overview](/guide/collaboration) and
[Collaboration → Server](/guide/collaboration).

## See also

- [Facade & Manager](/reference/facade) — the runtime entry points
- [Contracts reference](/reference/contracts) — the interfaces these FQCNs implement
- [Commands reference](/reference/commands) — the schedulable maintenance commands
