---
title: Configuration reference
description: Package-owned config keys — defaults, env vars, and accepted values.
outline: deep
---

# Configuration reference

Current keys from core and installed package config files, by section. Publish core with:

```bash
php artisan vendor:publish --tag=vaulter-config
```

Each table column: the config path (relative to `vaulter.` unless a module root is shown), the env override (when
one exists), the shipped default, and what it controls. Values are read back through
the package-owned `Configuration\*Config` classes, injected into services instead of raw
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
| `models.drive` | `Vaulter\Models\Drive\Drive` | core |
| `models.node` | `Vaulter\Models\Drive\Node` | core |
| `models.file` | `Vaulter\Models\Drive\File` | core |
| `models.file_revision` | `Vaulter\Models\Drive\FileRevision` | core |
| `models.blob` | `Vaulter\Models\Cas\Blob` | core |
| `models.file_revision_blob` | `Vaulter\Models\Drive\FileRevisionBlob` | core |
| `models.grant` | `Vaulter\Models\Access\NodeGrant` | core |
| `models.node_share` | `Vaulter\Models\Sharing\NodeShare` | core |
| `vaulter-drive.models.upload_session` | `Vaulter\Drive\Models\Uploads\UploadSession` | `axioma-studio/vaulter-drive` |
| `vaulter-drive.models.upload_part` | `Vaulter\Drive\Models\Uploads\UploadPart` | `axioma-studio/vaulter-drive` |
| `vaulter-documents.models.node_comment` | `Vaulter\Documents\Models\Comments\NodeComment` | `axioma-studio/vaulter-documents` |
| `vaulter-documents.models.document_revision` | `Vaulter\Documents\Models\Revisions\DocumentRevision` | `axioma-studio/vaulter-documents` |
| `models.activity` | `Vaulter\Models\Activity\Activity` | core |
| `models.outbox` | `Vaulter\Models\Webhooks\Outbox` | core |
| `models.webhook_endpoint` | `Vaulter\Models\Webhooks\WebhookEndpoint` | core |
| `models.webhook_delivery` | `Vaulter\Models\Webhooks\WebhookDelivery` | core |

Module models are configured under `vaulter-drive.models.*` and `vaulter-documents.models.*`; core never merges them into its own model map.

## Database — `database.*`

Tables use canonical names with `database.table_prefix` (default `v_`).
`database.connection` (`VAULTER_DB_CONNECTION`, default `null`) selects the connection.
`table_names` is read only by upgrade migrations through `DatabaseConfig::legacyTable()`.

## Primary keys — `ids.*`

| Key | Env | Default | Accepts |
|---|---|---|---|
| `ids.strategy` | `VAULTER_ID_STRATEGY` | `ulid` | `ulid` \| `uuid7` \| `bigint` |
| `ids.strategies.bigint` | — | `Vaulter\Schema\AutoIncrementIds` | an `IdStrategy` FQCN |
| `ids.strategies.ulid` | — | `Vaulter\Schema\UlidIds` | an `IdStrategy` FQCN |
| `ids.strategies.uuid7` | — | `Vaulter\Schema\Uuid7Ids` | an `IdStrategy` FQCN |

Choose `ids.strategy` directly. Deprecated `id_type` is normalized (`id` → `bigint`, `ulid` → `ulid`); differing custom canonical values fail boot. Register a custom strategy under `ids.strategies`.

## Profiles — `profiles.*`

| Key | Default |
|---|---|
| `profiles.providers` | `[]` |
| `profiles.default` | `Vaulter\Profiles\DefaultProfile` |

Both keys accept `ProfileProvider` FQCNs. Profile keys are unique and the default is registered.

## Features — `features.*`

Global feature toggles, read by `VaulterConfig::featureEnabled()`; a panel may override any
of these per-panel.

| Key | Default | Gates |
|---|---|---|
| `features.grants` | `true` | Per-node grants (the ACL) |
| `features.activity` | `true` | The activity log |
| `features.share_links` | `false` | Bearer node share links |
| `features.public_listing` | `false` | Public listing |
| `features.webhooks` | `true` | The outbox subscriber + endpoint routes |

## Document handlers — `handlers`

A list of `FileHandler` FQCNs, resolved by the core-owned `Vaulter\Handlers\FileHandlerRegistry`
by mime → extension → capability → priority, falling back to the `DownloadOnlyHandler`
null-object. The two baseline entries are core's OWN default (VLT-CANON P0.4/D10 — resolved even
without `vaulter-documents` installed). File handlers serve files only: document nodes are
presented by their [document type](/guide/document-types).

| Provider-tagged built-ins | Owner |
|---|---|
| `Vaulter\Handlers\Builtin\PreviewHandler` | core |
| `Vaulter\Handlers\Builtin\DownloadOnlyHandler` | core |

Add a new file format with one class + an entry here (or `Vaulter::handlers()->register()`).

## Tenancy — `tenancy.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `tenancy.enabled` | `VAULTER_TENANT_ENABLED` | `false` | Host tenancy opt-in; column name is always `tenant_id`. Supply a tenant-aware model |

See [Auth → Multi-tenancy](/guide/multi-tenancy).

## Table prefix — `database.table_prefix`

| Key | Env | Default |
|---|---|---|
| `database.table_prefix` | `VAULTER_TABLE_PREFIX` | `v_` |

Prefix for every Vaulter table (migrations and models read it), so the package
coexists with host tables.

## Authorization

### Drive ACL — `access.permission_driver`, `access.permission_drivers.*`

| Key | Env | Default | Accepts |
|---|---|---|---|
| `access.permission_driver` | `VAULTER_PERMISSION_DRIVER` | `database` | a key in `access.permission_drivers` |
| `access.permission_drivers.database` | — | `Vaulter\Access\Drivers\DatabasePermissionDriver` | standalone — authorises purely from grant rows |
| `access.permission_drivers.gate` | — | `Vaulter\Access\Drivers\GatePermissionDriver` | the Laravel Gate driver |
| `access.permission_drivers.spatie` | — | `Vaulter\Access\Drivers\SpatiePermissionDriver` | the spatie/laravel-permission driver |
| `access.permission_drivers.azgard` | — | `Vaulter\Azgard\AzgardPermissionDriver` | registered by the installed `vaulter-azgard` provider |
| `access.permission_drivers.null` | — | `Vaulter\Testing\NullPermissionDriver` | open, **non-production only** |

::: danger Null driver in production
The `null` driver is open and env-gated. Production config validation rejects a panel
bound to it — bind a real driver before deploying.
:::

### Gate driver map — `access.gate.*`

Used only when `access.permission_driver = 'gate'`. Maps a `GrantLevel` to a host Gate
ability; the highest allowed level becomes the actor's role grant.

| Key | Env | Default |
|---|---|---|
| `access.gate.abilities.reader` | — | `vaulter.view` |
| `access.gate.abilities.commenter` | — | `vaulter.comment` |
| `access.gate.abilities.writer` | — | `vaulter.edit` |
| `access.gate.abilities.organizer` | — | `vaulter.organize` |
| `access.gate.abilities.owner` | — | `vaulter.manage` |
| `access.gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `vaulter.super` |
| `access.gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `vaulter.create` |

### Spatie driver map — `access.spatie.*`

Used only when `access.permission_driver = 'spatie'` **and** spatie/laravel-permission is
installed.

| Key | Env | Default |
|---|---|---|
| `access.spatie.permissions.reader` | — | `vaulter.view` |
| `access.spatie.permissions.commenter` | — | `vaulter.comment` |
| `access.spatie.permissions.writer` | — | `vaulter.edit` |
| `access.spatie.permissions.organizer` | — | `vaulter.organize` |
| `access.spatie.permissions.owner` | — | `vaulter.manage` |
| `access.spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `vaulter.super` |
| `access.spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `vaulter.create` |
| `access.spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` |
| `access.spatie.role_subject_type` | — | `role` |
| `access.spatie.team_subject_type` | — | `team` |

`access.spatie.teams` falls back here only when the host's own `config('permission.teams')`
is unset. `role_subject_type`/`team_subject_type` are the morph aliases role/team
grant rows are addressed to.

### AzGuard driver map — `vaulter-azgard.*`

Separate package: `composer require axioma-studio/vaulter-azgard`. The settings below
come from `config/vaulter-azgard.php`, rather than core configuration.

| Key | Default |
|---|---|
| `vaulter-azgard.panel_id` | `null` (resolve the panel from the permission key) |

Used only when `access.permission_driver = 'azgard'`. Action-centric — maps a Vaulter
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

## Content-addressed blob store — `vaulter-media.*`

The swappable CAS services, all bound in the provider and resolved through the
container.

| Key | Default | Contract |
|---|---|---|
| `vaulter-media.content_store` | `Vaulter\Media\Cas\Sha256ContentStore` | `ContentStore` |
| `vaulter-media.storage_driver` | `Vaulter\Media\Cas\FlysystemStorageDriver` | `StorageDriver` |
| `vaulter-media.blob_store` | `Vaulter\Media\Cas\BlobStore` | `BlobStore` |

## Deduplication — `vaulter-media.dedup.scope`

| Key | Env | Default | Accepts |
|---|---|---|---|
| `vaulter-media.dedup.scope` | `VAULTER_DEDUP_SCOPE` | `tenant` | `global` \| `tenant` \| `drive` |

The boundary in which content is deduplicated. Uniqueness uses the stored
`scope_key` and hash. `global` shares bytes across the installation, `tenant` across
one tenant and `drive` within one drive. Changing the scope affects new uploads;
existing blobs retain their scope.

## Blob GC — `vaulter-media.gc.grace_hours`

| Key | Env | Default | Notes |
|---|---|---|---|
| `vaulter-media.gc.grace_hours` | `VAULTER_GC_GRACE_HOURS` | `24` | Two-phase GC grace window; floored at 1h |
| `vaulter-media.gc.orphan_grace_hours` | `VAULTER_ORPHAN_GRACE_HOURS` | falls back to `VAULTER_GC_GRACE_HOURS` (`24`) | Grace window for the orphan disk-walk (CAS reconciler) — a separate window from `vaulter-media.gc.grace_hours`, so a just-promoted object isn't mistaken for orphaned bytes |

A blob whose ref-count cache reaches zero is tombstoned with
`delete_after_at = now + grace`; only after the grace passes are its bytes swept. See
[Recipes → Blob GC](/guide/recipes).

## CAS storage disk — `vaulter-media.disk`

| Key | Env | Default | Notes |
|---|---|---|---|
| `vaulter-media.disk` | `VAULTER_DISK` | `local` | The default Flysystem disk for canonical CAS objects when a blob's `v_storages.disk` doesn't pin its own |

::: warning Keep the CAS bucket unversioned
Never use S3 lifecycle/versioning as a blob-deletion mechanism.
:::

## Sharing

### Bearer node share links — `sharing.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `sharing.token_generator` | — | `Vaulter\Support\RandomShareTokenGenerator` | The `ShareTokenGenerator` minting the raw bearer token (only its sha256 hash is stored) |
| `sharing.url_base` | `VAULTER_SHARE_URL_BASE` | `/share` | The public base a share URL is built from |
| `sharing.prefix_length` | — | `8` | How many leading chars of the raw token are stored as the non-secret `token_prefix` |

See [Usage → Sharing](/guide/sharing).

## Trash — `vaulter-drive.trash.retention_days`

| Key | Env | Default |
|---|---|---|
| `vaulter-drive.trash.retention_days` | `VAULTER_TRASH_RETENTION_DAYS` | `30` |

The retention window before a trashed node's subtree is permanently force-deleted by
`php artisan vaulter:purge-trash`.

## Uploads — `vaulter-drive.uploads.*`

| Key | Default | Env |
|---|---|---|
| `vaulter-drive.uploads.max_file_size` | `5368709120` bytes | `VAULTER_UPLOAD_MAX_BYTES` |
| `vaulter-drive.uploads.allowed_mime_types` | `[]` | — |
| `vaulter-drive.uploads.resumable.enabled` | `true` | `VAULTER_UPLOAD_TUS_ENABLED` |
| `vaulter-drive.uploads.resumable.chunk_size` | `8388608` bytes | `VAULTER_UPLOAD_CHUNK_SIZE` |
| `vaulter-drive.uploads.resumable.max_chunk_size` | `67108864` bytes | `VAULTER_UPLOAD_MAX_CHUNK_BYTES` |
| `vaulter-drive.uploads.resumable.session_ttl_hours` | `24` | `VAULTER_UPLOAD_SESSION_TTL_HOURS` |
| `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` | `20` | `VAULTER_UPLOAD_MAX_ACTIVE_SESSIONS` |
| `vaulter-drive.uploads.resumable.metadata.allowed_keys` | `['filename', 'filetype', 'name', 'mime_type']` | — |
| `vaulter-drive.uploads.resumable.metadata.max_value_length` | `512` | — |

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

The `features.webhooks` flag gates the outbox subscriber and endpoint routes; tables always exist. See [Recipes → Reliability & webhooks](/guide/recipes).

## API routes — `routes.*`

| Key | Env | Default |
|---|---|---|
| `routes.prefix` | `VAULTER_ROUTE_PREFIX` | `api/vaulter` |

## Document node versions — `vaulter-documents.revisions.*`

| Key | Default | Controls |
|---|---|---|
| `vaulter-documents.revisions.limit` | `50` | Stored checkpoints per document before pruning (`0` = unlimited) |
| `vaulter-documents.revisions.checkpoint_interval_seconds` | `300` | Seconds the working state may run ahead of its last autosave checkpoint (`0` = checkpoint every effective save). The 0.2 `autosave_debounce_seconds` key is deprecated and ignored |

See [Usage → Versions](/guide/documents).

## Documents — `vaulter-documents.types`, `default_type`, `content.max_size_kb`

| Key | Default |
|---|---|
| `vaulter-documents.types` | `[RichTextDocumentType::class, MarkdownDocumentType::class]` — the registered [document types](/guide/document-types); add `SpreadsheetDocumentType::class` or your own |
| `vaulter-documents.default_type` | `'vaulter/rich-text'` — the type `create()` uses when none is named; must be registered |
| `vaulter-documents.content.max_size_kb` | `5120` — the limit of a codec whose `maxSizeBytes()` is `null` |

The 0.2 keys are normalized with a deprecation: `vaulter.enabled_document_types`
(`richtext`/`table`/`template` → `RichTextDocumentType`, `markdown` → `MarkdownDocumentType`)
and `vaulter.features.spreadsheets = true` (adds `SpreadsheetDocumentType`) become
`vaulter-documents.types`. The mapped list replaces the default one, so a list without a rich-text
format (`['markdown']`) leaves `vaulter/rich-text` unregistered and the application refuses to boot
until `vaulter-documents.default_type` names a registered type (`vaulter/markdown`).

## Export formats — `vaulter-documents.exporters`

Map of format key → `DocumentExporter` FQCN.

| Key | Default |
|---|---|
| `vaulter-documents.exporters.html` | `Vaulter\Documents\Export\HtmlExporter` |
| `vaulter-documents.exporters.markdown` | `Vaulter\Documents\Export\MarkdownExporter` |

Add your own (PDF, DOCX, …) here or via `Vaulter::registerExporter()`. See
[Recipes → Custom export](/guide/recipes).

## Collaboration — `vaulter-documents.collaboration.*`

Yjs is the source of truth; TipTap JSON is the derived projection. The transport is
swappable behind the driver.

| Key | Env | Default | Controls |
|---|---|---|---|
| `vaulter-documents.collaboration.driver` | `VAULTER_COLLAB_DRIVER` | `hocuspocus` | `hocuspocus` |
| `vaulter-documents.collaboration.broadcast.channel_prefix` | — | `vaulter.documents` | The room namespace; a room is always `prefix.nodeId` |
| `vaulter-documents.collaboration.hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | `null` | Shared secret the Node hub presents on the S2S webhook endpoints. Required for signed webhook bodies when collaboration is enabled |
| `vaulter-documents.collaboration.hub.url` | `VAULTER_COLLAB_URL` | `ws://localhost:1234` | The hub WebSocket URL |
| `vaulter-documents.collaboration.hub.jwt_secret` | `VAULTER_COLLAB_SECRET` | `null` | Room-JWT signing key **and** the key the webhooks verify a JWT against |
| `vaulter-documents.collaboration.hub.token_ttl` | — | `3600` | Room-JWT TTL (seconds) |

See [Collaboration → Overview](/guide/collaboration) and
[Collaboration → Server](/guide/collaboration).

## See also

- [Facade & Manager](/reference/facade) — the runtime entry points
- [Contracts reference](/reference/contracts) — the interfaces these FQCNs implement
- [Commands reference](/reference/commands) — the schedulable maintenance commands

## Additional settings

| Key | Default |
|---|---|
| `vaulter-documents.comments.enabled` | `true` |
| `vaulter-documents.collaboration.enabled` | `false` |
| `vaulter-documents.collaboration.hub.timestamp_tolerance` | `300` seconds |
| `vaulter-documents.tiptap.extra_nodes` | `[]` |
| `vaulter-documents.tiptap.extra_marks` | `[]` |
| `vaulter-documents.tiptap.extra_attributes` | `[]` |
| `vaulter-documents.tiptap.url_attributes` | `[]` |
| `vaulter-media.gc.orphan_grace_hours` | `24` (`VAULTER_ORPHAN_GRACE_HOURS`, fallback `VAULTER_GC_GRACE_HOURS`) |
| `ids.host_keys` | `string` (`VAULTER_HOST_KEYS`) |
| `listing.scan_budget` | `1000` |
| `routes.middleware.authenticated` | `['api', 'auth:sanctum']` |
| `routes.middleware.public` | `['api']` |
| `routes.middleware.hub` | `['api']` |
| `routes.rate_limits.public` | `60` (`VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE`) |
| `routes.rate_limits.uploads` | `120` (`VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE`) |
| `sharing.default_ttl_hours` | `72` (`VAULTER_SHARE_DEFAULT_TTL_HOURS`) |
| `sharing.max_ttl_hours` | `720` (`VAULTER_SHARE_MAX_TTL_HOURS`) |
| `downloads.inline_mime_types` | `['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain']` |
| `observability.enabled` | `false` (`VAULTER_OBSERVABILITY_ENABLED`) |
| `observability.recorder` | `null` (`VAULTER_OBSERVABILITY_RECORDER`) |
| `observability.tracing.enabled` | `false` (`VAULTER_TRACING_ENABLED`) |
| `observability.tracing.recorder` | `null` (`VAULTER_TRACING_RECORDER`) |
| `webhooks.retry_claim_seconds` | `60` |
| `webhooks.delivery.url_guard` | `Vaulter\Webhooks\DefaultOutboundUrlGuard` |
| `webhooks.delivery.allowed_schemes` | `['http', 'https']` |
| `webhooks.delivery.blocked_ip_ranges` | `null` (closed built-in ranges) |
| `webhooks.delivery.allowed_ip_ranges` | `[]` |
| `webhooks.delivery.connect_timeout` | `2` seconds |
| `webhooks.delivery.max_response_bytes` | `65536` bytes |

Documents are enabled when their package is installed; blobs and file revisions are always enabled.
Comments now default to true; a legacy `features.comments = false` is preserved by normalization.
Exporters remain a format → FQCN map. `enabled_document_types` and `features.spreadsheets`
became `vaulter-documents.types`. See the [migration map](/guide/configuration#migrating-published-0-2-configuration).
