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

## Primary keys — `ids.*`

| Key | Env | Default | Accepts |
|---|---|---|---|
| `ids.strategy` | `VAULTER_ID_STRATEGY` | `ulid` | `ulid` \| `uuid7` \| `bigint` |
| `ids.strategies.bigint` | — | `Vaulter\Schema\AutoIncrementIds` | an `IdStrategy` FQCN |
| `ids.strategies.ulid` | — | `Vaulter\Schema\UlidIds` | an `IdStrategy` FQCN |
| `ids.strategies.uuid7` | — | `Vaulter\Schema\Uuid7Ids` | an `IdStrategy` FQCN |

Choose `ids.strategy` directly. Register a custom strategy under `ids.strategies`.

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
| `features.changes` | `true` | The drive change journal behind `DriveHandle::changes()` |

## Document handlers — `handlers`

A list of `FileHandler` FQCNs, resolved by the core-owned `Vaulter\Handlers\FileHandlerRegistry`
by mime → extension → capability → priority, falling back to the `DownloadOnlyHandler`
null-object. The two baseline entries are core's OWN default (VLT-CANON P0.4/D10 — resolved even
without `vaulter-documents` installed). File handlers serve files only: document nodes are
presented by their [document type](/extending/document-types).

| Provider-tagged built-ins | Owner |
|---|---|
| `Vaulter\Handlers\Builtin\PreviewHandler` | core |
| `Vaulter\Handlers\Builtin\DownloadOnlyHandler` | core |

Add a new file format with one class + an entry here (or `Vaulter::handlers()->register()`).

## Tenancy — `tenancy.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `tenancy.enabled` | `VAULTER_TENANT_ENABLED` | `false` | Host tenancy opt-in; column name is always `tenant_id`. Supply a tenant-aware model |

See [Auth → Multi-tenancy](/concepts/tenancy).

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
and align ULID morphs — see [Permission drivers](/extending/permission-drivers).

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
[Recipes → Blob GC](/guides/recipes).

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

See [Usage → Sharing](/guides/sharing).

## Trash — `vaulter-drive.trash.retention_days`

| Key | Env | Default |
|---|---|---|
| `vaulter-drive.trash.retention_days` | `VAULTER_TRASH_RETENTION_DAYS` | `30` |

The retention window before a trashed node's subtree is permanently force-deleted by
`php artisan vaulter:trash:purge`.

## Drives — `vaulter.drives.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `vaulter.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | `HasDrives`: deleting the owner model deletes its drives (system actor `owner-deleted`); with `SoftDeletes` only `forceDeleted` counts |

See [Drive · lifecycle](/concepts/nodes-and-revisions#drive-lifecycle).

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

A transactional outbox + [Standard Webhooks](/guides/webhooks#headers-and-signature) signed delivery
(at-least-once, a retry schedule, DLQ).

**Removed in 1.0** — a published config that still sets one of these fails boot with
`InvalidConfigurationException` naming the replacement: `webhooks.max_attempts` and `webhooks.backoff`
(→ `retry_schedule`), `webhooks.disable_after_failures` (→ `disable_after_days`),
`webhooks.signature_header`, `webhooks.timestamp_header` and `webhooks.event_header` (the headers are
fixed by the standard).

| Key | Env | Default | Controls |
|---|---|---|---|
| `webhooks.timeout` | — | `15` | HTTP request timeout (seconds) per delivery POST |
| `webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Seconds to wait after the n-th failed attempt; attempts = entries + 1 (about three days), the last failure makes the delivery dead |
| `webhooks.retry_jitter` | — | `0.1` | Fraction (0–0.5) added or removed at random from each retry delay |
| `webhooks.disable_after_days` | — | `5` | Days of unbroken failure after which an endpoint is switched off (`0` = never) |
| `webhooks.secret_rotation_overlap` | — | `86400` | Seconds the previous secret keeps signing after a rotation (at most 604800) |
| `webhooks.retention_days` | — | `30` | Age past which completed outbox rows + terminal deliveries are prunable (pending rows never are) |
| `webhooks.relay.claim_seconds` | — | `60` | How long a relay worker leases an outbox row; a crashed worker's row is resumed after it. Also how long a pending delivery may wait for its job before the retry sweep re-queues it |
| `webhooks.relay.fanout_chunk` | — | `100` | Endpoints written per fan-out transaction (integer 1..1000; anything else fails boot) |
| `webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `vaulter.webhooks.manage` | The Gate ability gating the endpoint-management surface; `null` allows any authenticated user |

The `features.webhooks` flag gates the outbox subscriber and endpoint routes; tables always exist. See [Recipes → Reliability & webhooks](/guides/recipes).

## Change journal — `changes.*`

The per-drive [change journal](/concepts/events) syncing clients read through `DriveHandle::changes()`.
`features.changes` (default `true`) switches the writing of entries; the tables always exist.
Turning it off and on again leaves a gap only a reset closes (`vaulter:changes:reset`).

| Key | Env | Default | Controls |
|---|---|---|---|
| `changes.retention_days` | — | `30` | Days an entry stays readable; `vaulter:changes:prune` deletes older ones and a cursor behind them expires (`410 cursor_expired`). Integer ≥ 1, anything else fails boot |
| `changes.max_page_size` | — | `1000` | The largest `limit` of one `changes()->list()` page (integer 1..10000, anything else fails boot) |

## Scheduling — `schedule.*`

Which maintenance commands the packages put on Laravel's scheduler and how often; the command
list and the value grammar are in [Commands → Scheduling](/reference/commands#scheduling).

| Key | Env | Default | Controls |
|---|---|---|---|
| `schedule.enabled` | `VAULTER_SCHEDULE_ENABLED` | `true` | Master switch for every Vaulter scheduled task (core, drive, media); set `false` when the host schedules the commands itself. Must be a boolean |
| `schedule.webhooks_relay` | — | `'everyMinute'` | `vaulter:webhooks:relay`; `null` turns it off |
| `schedule.webhooks_retry` | — | `'everyMinute'` | `vaulter:webhooks:retry`; `null` turns it off |
| `schedule.webhooks_prune` | — | `'dailyAt:03:10'` | `vaulter:webhooks:prune`; `null` turns it off |
| `schedule.changes_prune` | — | `'dailyAt:03:20'` | `vaulter:changes:prune`; `null` turns it off |
| `vaulter-drive.schedule.*` | — | `uploads_sweep` `hourlyAt:15`, `trash_purge` `dailyAt:03:30`, `drives_purge` `hourlyAt:25`, `revisions_prune` `dailyAt:03:40`, `scan_reconcile` `everyFiveMinutes`, `drives_recount` `null` | Drive tasks; `scan_reconcile` runs only while the scan mode is not `off` and does not depend on `features.webhooks` |
| `vaulter-media.schedule.*` | — | `blobs_gc` `dailyAt:04:00`, `blobs_reconcile` `weeklyOn:0,04:30` | Blob tasks |

An invalid value fails boot with `InvalidConfigurationException`; a key a host leaves out of its
`schedule` array gets the package default — set it to `null` to turn the task off.

## API routes — `routes.*`

| Key | Env | Default |
|---|---|---|
| `routes.prefix` | `VAULTER_ROUTE_PREFIX` | `api/vaulter` |

## Document node versions — `vaulter-documents.revisions.*`

| Key | Default | Controls |
|---|---|---|
| `vaulter-documents.revisions.limit` | `50` | Stored checkpoints per document before pruning (`0` = unlimited) |
| `vaulter-documents.revisions.checkpoint_interval_seconds` | `300` | Seconds the working state may run ahead of its last autosave checkpoint (`0` = checkpoint every effective save). |

See [Usage → Versions](/guides/documents).

## Documents — `vaulter-documents.types`, `default_type`, `content.max_size_kb`

| Key | Default |
|---|---|
| `vaulter-documents.types` | `[RichTextDocumentType::class, MarkdownDocumentType::class]` — the registered [document types](/extending/document-types); add `SpreadsheetDocumentType::class` or your own |
| `vaulter-documents.default_type` | `'vaulter/rich-text'` — the type `create()` uses when none is named; must be registered |
| `vaulter-documents.content.max_size_kb` | `5120` — the limit of a codec whose `maxSizeBytes()` is `null` |

A list without a rich-text type (`['markdown']`) leaves `vaulter/rich-text` unregistered, and the application refuses to boot
until `vaulter-documents.default_type` names a registered type (`vaulter/markdown`).

## Export formats — `vaulter-documents.exporters`

List of `DocumentExporter` class names, resolved by (document type, format). Two exporters for one
pair fail at boot; the set freezes after boot.

| Key | Default |
|---|---|
| `vaulter-documents.exporters` | `[HtmlExporter::class, MarkdownExporter::class]` (`Vaulter\Documents\Export\*`) |

Add your own (PDF, DOCX, …) here or via `Documents::exporters()->register()`. See
[Recipes → Custom export](/guides/recipes).

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

See [Collaboration → Overview](/guides/collaboration) and
[Collaboration → Server](/guides/collaboration).

## See also

- [Facade & Manager](/reference/php-api) — the runtime entry points
- [Contracts reference](/reference/php-api) — the interfaces these FQCNs implement
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
Comments default to true; set `vaulter-documents.comments.enabled = false` to turn them off.

## All keys (generated)

<!-- vaulter:config-reference:start -->
### `vaulter-azgard`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-azgard.panel_id` | `VAULTER_AZGARD_PANEL` | `null` | AzGuard panel The AzGuard v1 panel the adapter passes as `guard:` to every check. null (the default) lets AzGuard select the panel — by a full name (`app:documents.view`) or a panel prefix in the permission, else the panel of the request, else the panel of the user model. The default map below names local keys (`documents.view`) of that panel, so the panel's resource prefix must not be `documents` (AzGuard refuses such a panel with PrefixConflictException): give the panel another resourcePrefix() or map the abilities below to other permissions. Set an explicit panel id when the user model belongs to several panels or the checks must not depend on the request panel; AzGuard throws when no panel can be selected. |
| `vaulter-azgard.permissions.view` | — | `'documents.view'` | — |
| `vaulter-azgard.permissions.download` | — | `'documents.download'` | — |
| `vaulter-azgard.permissions.comment` | — | `'documents.comment'` | — |
| `vaulter-azgard.permissions.edit` | — | `'documents.update'` | — |
| `vaulter-azgard.permissions.create` | — | `'documents.create'` | — |
| `vaulter-azgard.permissions.move` | — | `'documents.update'` | — |
| `vaulter-azgard.permissions.copy` | — | `'documents.update'` | — |
| `vaulter-azgard.permissions.trash` | — | `'documents.delete'` | — |
| `vaulter-azgard.permissions.delete` | — | `'documents.delete'` | — |
| `vaulter-azgard.permissions.restore` | — | `'documents.delete'` | — |
| `vaulter-azgard.permissions.share` | — | `'documents.manage'` | — |
| `vaulter-azgard.permissions.manage` | — | `'documents.manage'` | — |
| `vaulter-azgard.create_permission` | `VAULTER_AZGARD_CREATE_PERMISSION` | `'documents.create'` | — |

### `vaulter`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter.database.connection` | `VAULTER_DB_CONNECTION` | `null` | — |
| `vaulter.database.table_prefix` | `VAULTER_TABLE_PREFIX` | `'v_'` | — |
| `vaulter.database.write_guard` | `VAULTER_DB_WRITE_GUARD` | `true` | Refuse writes to Vaulter models outside the Vaulter operations (defence in depth, not a sandbox). |
| `vaulter.ids.strategy` | `VAULTER_ID_STRATEGY` | `'ulid'` | — |
| `vaulter.ids.host_keys` | `VAULTER_HOST_KEYS` | `'string'` | — |
| `vaulter.ids.strategies.bigint` | — | `AutoIncrementIds::class` | — |
| `vaulter.ids.strategies.ulid` | — | `UlidIds::class` | — |
| `vaulter.ids.strategies.uuid7` | — | `Uuid7Ids::class` | — |
| `vaulter.tenancy.enabled` | `VAULTER_TENANT_ENABLED` | `false` | — |
| `vaulter.tenancy.resolver` | — | `null` | — |
| `vaulter.profiles.default` | — | `DefaultProfile::class` | — |
| `vaulter.profiles.providers` | — | `[]` | — |
| `vaulter.scan.mode` | — | `null` | — |
| `vaulter.scan.required` | — | `null` | — |
| `vaulter.scan.scanner` | — | `null` | — |
| `vaulter.scan.scanner_key` | — | `null` | — |
| `vaulter.scan.scanner_version` | — | `null` | — |
| `vaulter.scan.policy_version` | — | `null` | — |
| `vaulter.scan.rollout_key` | — | `null` | — |
| `vaulter.modules` | — | `[]` | Module activation overrides (D22): module id => true\|false. Auto modules (media, drive, documents…) are active unless switched off; opt-in modules (webhooks) only when switched on. |
| `vaulter.features.grants` | — | `true` | — |
| `vaulter.features.activity` | — | `true` | — |
| `vaulter.features.changes` | — | `true` | The drive change journal behind DriveHandle::changes() (PLAN2.D50). |
| `vaulter.features.share_links` | — | `false` | — |
| `vaulter.features.public_listing` | — | `false` | — |
| `vaulter.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | HasDrives: delete the drives of an owner when the owner is deleted. |
| `vaulter.access.permission_driver` | `VAULTER_PERMISSION_DRIVER` | `'database'` | — |
| `vaulter.access.permission_drivers.database` | — | `DatabasePermissionDriver::class` | — |
| `vaulter.access.permission_drivers.gate` | — | `GatePermissionDriver::class` | — |
| `vaulter.access.permission_drivers.spatie` | — | `SpatiePermissionDriver::class` | — |
| `vaulter.access.permission_drivers.null` | — | `NullPermissionDriver::class` | — |
| `vaulter.access.gate.abilities.previewer` | — | `'vaulter.preview'` | — |
| `vaulter.access.gate.abilities.reader` | — | `'vaulter.view'` | — |
| `vaulter.access.gate.abilities.commenter` | — | `'vaulter.comment'` | — |
| `vaulter.access.gate.abilities.writer` | — | `'vaulter.edit'` | — |
| `vaulter.access.gate.abilities.organizer` | — | `'vaulter.organize'` | — |
| `vaulter.access.gate.abilities.owner` | — | `'vaulter.manage'` | — |
| `vaulter.access.gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `'vaulter.super'` | — |
| `vaulter.access.gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `'vaulter.create'` | — |
| `vaulter.access.spatie.permissions.previewer` | — | `'vaulter.preview'` | — |
| `vaulter.access.spatie.permissions.reader` | — | `'vaulter.view'` | — |
| `vaulter.access.spatie.permissions.commenter` | — | `'vaulter.comment'` | — |
| `vaulter.access.spatie.permissions.writer` | — | `'vaulter.edit'` | — |
| `vaulter.access.spatie.permissions.organizer` | — | `'vaulter.organize'` | — |
| `vaulter.access.spatie.permissions.owner` | — | `'vaulter.manage'` | — |
| `vaulter.access.spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `'vaulter.super'` | — |
| `vaulter.access.spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `'vaulter.create'` | — |
| `vaulter.access.spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` | — |
| `vaulter.access.spatie.role_subject_type` | — | `'role'` | — |
| `vaulter.access.spatie.team_subject_type` | — | `'team'` | — |
| `vaulter.routes.prefix` | `VAULTER_ROUTE_PREFIX` | `'api/vaulter'` | — |
| `vaulter.routes.middleware.authenticated` | — | `['api', 'auth:sanctum']` | — |
| `vaulter.routes.middleware.public` | — | `['api']` | — |
| `vaulter.routes.middleware.hub` | — | `['api']` | — |
| `vaulter.routes.rate_limits.public` | `VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE` | `60` | — |
| `vaulter.routes.rate_limits.uploads` | `VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE` | `120` | — |
| `vaulter.listing.scan_budget` | — | `1000` | — |
| `vaulter.sharing.token_generator` | — | `RandomShareTokenGenerator::class` | — |
| `vaulter.sharing.url_base` | `VAULTER_SHARE_URL_BASE` | `'/share'` | — |
| `vaulter.sharing.prefix_length` | — | `8` | — |
| `vaulter.sharing.default_ttl_hours` | `VAULTER_SHARE_DEFAULT_TTL_HOURS` | `72` | — |
| `vaulter.sharing.max_ttl_hours` | `VAULTER_SHARE_MAX_TTL_HOURS` | `720` | — |
| `vaulter.downloads.delivery_mode` | — | `'strict'` | — |
| `vaulter.downloads.direct_ttl_seconds` | — | `60` | — |
| `vaulter.downloads.inline_mime_types` | — | `['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain']` | — |
| `vaulter.webhooks.timeout` | — | `15` | — |
| `vaulter.webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Seconds to wait after the n-th failed attempt; attempts = entries + 1 (about three days in total). Jitter is the +/- fraction applied to each delay. |
| `vaulter.webhooks.retry_jitter` | — | `0.1` | — |
| `vaulter.webhooks.disable_after_days` | — | `5` | Days of uninterrupted failure before an endpoint is disabled; 0 never disables. |
| `vaulter.webhooks.secret_rotation_overlap` | — | `86400` | Seconds the previous secret keeps signing after a rotation (max 604800). |
| `vaulter.webhooks.retention_days` | — | `30` | — |
| `vaulter.webhooks.retry_claim_seconds` | — | `60` | — |
| `vaulter.webhooks.relay` | — | — | The outbox relay leases a row for claim_seconds while it materializes deliveries fanout_chunk endpoints at a time (1..1000); a crashed relay's row is picked up again once the lease lapses. |
| `vaulter.webhooks.relay.claim_seconds` | — | `60` | — |
| `vaulter.webhooks.relay.fanout_chunk` | — | `100` | — |
| `vaulter.webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `'vaulter.webhooks.manage'` | — |
| `vaulter.webhooks.delivery.url_guard` | — | `DefaultOutboundUrlGuard::class` | — |
| `vaulter.webhooks.delivery.allowed_schemes` | — | `['http', 'https']` | — |
| `vaulter.webhooks.delivery.blocked_ip_ranges` | — | `null` | — |
| `vaulter.webhooks.delivery.allowed_ip_ranges` | — | `[]` | — |
| `vaulter.webhooks.delivery.connect_timeout` | — | `2` | — |
| `vaulter.webhooks.delivery.max_response_bytes` | — | `65536` | — |
| `vaulter.changes.retention_days` | — | `30` | Days a journal entry stays readable; vaulter:changes:prune deletes older ones and a cursor behind them expires (410 cursor_expired). |
| `vaulter.changes.max_page_size` | — | `1000` | The largest page DriveHandle::changes()->list() returns. |
| `vaulter.schedule` | — | — | Maintenance tasks put on the host scheduler. Value: null (off) \| everyMinute \| everyFiveMinutes hourly \| daily \| weekly \| 'dailyAt:HH:MM' \| 'hourlyAt:M' \| 'weeklyOn:D,HH:MM' \| a 5-field cron expression. Set enabled to false when the host schedules the commands itself. |
| `vaulter.schedule.enabled` | `VAULTER_SCHEDULE_ENABLED` | `true` | — |
| `vaulter.schedule.webhooks_relay` | — | `'everyMinute'` | — |
| `vaulter.schedule.webhooks_retry` | — | `'everyMinute'` | — |
| `vaulter.schedule.webhooks_prune` | — | `'dailyAt:03:10'` | — |
| `vaulter.schedule.changes_prune` | — | `'dailyAt:03:20'` | — |
| `vaulter.handlers` | — | `[]` | — |
| `vaulter.observability.enabled` | `VAULTER_OBSERVABILITY_ENABLED` | `false` | — |
| `vaulter.observability.recorder` | `VAULTER_OBSERVABILITY_RECORDER` | `null` | — |
| `vaulter.observability.tracing.enabled` | `VAULTER_TRACING_ENABLED` | `false` | — |
| `vaulter.observability.tracing.recorder` | `VAULTER_TRACING_RECORDER` | `null` | — |
| `vaulter.models.drive` | — | `Drive::class` | — |
| `vaulter.models.node` | — | `Node::class` | — |
| `vaulter.models.file` | — | `File::class` | — |
| `vaulter.models.scan_obligation` | — | `ScanObligation::class` | — |
| `vaulter.models.scan_request` | — | `ScanRequest::class` | — |
| `vaulter.models.scan_evidence` | — | `ScanEvidenceRecord::class` | — |
| `vaulter.models.scan_policy_state` | — | `ScanPolicyState::class` | — |
| `vaulter.models.scan_backfill_run` | — | `ScanBackfillRun::class` | — |
| `vaulter.models.file_revision` | — | `FileRevision::class` | — |
| `vaulter.models.blob` | — | `Blob::class` | — |
| `vaulter.models.file_revision_blob` | — | `FileRevisionBlob::class` | — |
| `vaulter.models.grant` | — | `NodeGrant::class` | — |
| `vaulter.models.node_share` | — | `NodeShare::class` | — |
| `vaulter.models.activity` | — | `Activity::class` | — |
| `vaulter.models.outbox` | — | `Outbox::class` | — |
| `vaulter.models.webhook_endpoint` | — | `WebhookEndpoint::class` | — |
| `vaulter.models.webhook_delivery` | — | `WebhookDelivery::class` | — |

### `vaulter-documents`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-documents.types` | — | `[RichTextDocumentType::class, MarkdownDocumentType::class]` | Document type classes; add SpreadsheetDocumentType or your own here. |
| `vaulter-documents.default_type` | — | `'vaulter/rich-text'` | — |
| `vaulter-documents.content.max_size_kb` | — | `5120` | — |
| `vaulter-documents.revisions.limit` | — | `50` | — |
| `vaulter-documents.revisions.checkpoint_interval_seconds` | — | `300` | — |
| `vaulter-documents.comments.enabled` | — | `true` | — |
| `vaulter-documents.exporters` | — | `[HtmlExporter::class, MarkdownExporter::class]` | Document exporter classes, resolved by (document type, format). |
| `vaulter-documents.tiptap.extra_nodes` | — | `[]` | — |
| `vaulter-documents.tiptap.extra_marks` | — | `[]` | — |
| `vaulter-documents.tiptap.extra_attributes` | — | `[]` | — |
| `vaulter-documents.tiptap.url_attributes` | — | `[]` | — |
| `vaulter-documents.collaboration.enabled` | — | `false` | — |
| `vaulter-documents.collaboration.driver` | `VAULTER_COLLAB_DRIVER` | `'hocuspocus'` | — |
| `vaulter-documents.collaboration.hub.url` | `VAULTER_COLLAB_URL` | `'ws://localhost:1234'` | — |
| `vaulter-documents.collaboration.hub.jwt_secret` | `VAULTER_COLLAB_SECRET` | `null` | — |
| `vaulter-documents.collaboration.hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | `null` | — |
| `vaulter-documents.collaboration.hub.token_ttl` | — | `3600` | — |
| `vaulter-documents.collaboration.hub.timestamp_tolerance` | — | `300` | — |
| `vaulter-documents.collaboration.max_state_bytes` | — | `16777216` | The largest binary Yjs state one hub store may write (413 above it). |
| `vaulter-documents.collaboration.broadcast.channel_prefix` | — | `'vaulter.documents'` | — |
| `vaulter-documents.models.document_revision` | — | `DocumentRevision::class` | — |
| `vaulter-documents.models.node_comment` | — | `NodeComment::class` | — |

### `vaulter-drive`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-drive.uploads.scan.required` | — | `null` | — |
| `vaulter-drive.uploads.scan.mode` | — | `null` | — |
| `vaulter-drive.uploads.scan.scanner` | — | `null` | — |
| `vaulter-drive.uploads.scan.scanner_key` | — | `null` | — |
| `vaulter-drive.uploads.scan.scanner_version` | — | `null` | — |
| `vaulter-drive.uploads.scan.policy_version` | — | `null` | — |
| `vaulter-drive.uploads.scan.rollout_key` | — | `null` | — |
| `vaulter-drive.uploads.scan.queue` | — | `'default'` | — |
| `vaulter-drive.uploads.scan.lease_seconds` | — | `120` | — |
| `vaulter-drive.uploads.scan.scanner_timeout_seconds` | — | `60` | — |
| `vaulter-drive.uploads.scan.max_attempts` | — | `5` | — |
| `vaulter-drive.uploads.scan.publish_cooldown_seconds` | — | `30` | — |
| `vaulter-drive.uploads.scan.recovery_batch` | — | `100` | — |
| `vaulter-drive.uploads.scan.cleanup_batch` | — | `100` | — |
| `vaulter-drive.uploads.max_file_size` | `VAULTER_UPLOAD_MAX_BYTES` | `5_368_709_120` | — |
| `vaulter-drive.uploads.allowed_mime_types` | — | `[]` | — |
| `vaulter-drive.uploads.resumable.enabled` | `VAULTER_UPLOAD_TUS_ENABLED` | `true` | — |
| `vaulter-drive.uploads.resumable.chunk_size` | `VAULTER_UPLOAD_CHUNK_SIZE` | `8_388_608` | — |
| `vaulter-drive.uploads.resumable.max_chunk_size` | `VAULTER_UPLOAD_MAX_CHUNK_BYTES` | `67_108_864` | — |
| `vaulter-drive.uploads.resumable.session_ttl_hours` | `VAULTER_UPLOAD_SESSION_TTL_HOURS` | `24` | — |
| `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` | `VAULTER_UPLOAD_MAX_ACTIVE_SESSIONS` | `20` | — |
| `vaulter-drive.uploads.resumable.metadata.allowed_keys` | — | `['filename', 'filetype', 'name', 'mime_type']` | — |
| `vaulter-drive.uploads.resumable.metadata.max_value_length` | — | `512` | — |
| `vaulter-drive.trash.retention_days` | — | `30` | — |
| `vaulter-drive.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-drive.schedule.uploads_sweep` | — | `'hourlyAt:15'` | — |
| `vaulter-drive.schedule.trash_purge` | — | `'dailyAt:03:30'` | — |
| `vaulter-drive.schedule.drives_purge` | — | `'hourlyAt:25'` | — |
| `vaulter-drive.schedule.revisions_prune` | — | `'dailyAt:03:40'` | — |
| `vaulter-drive.schedule.scan_reconcile` | — | `'everyFiveMinutes'` | — |
| `vaulter-drive.schedule.drives_recount` | — | `null` | Report-only drift check; off until an operator wants it. |
| `vaulter-drive.models.upload_session` | — | `UploadSession::class` | — |
| `vaulter-drive.models.upload_part` | — | `UploadPart::class` | — |

### `vaulter-filament`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-filament.navigation_group` | — | `'Vaulter'` | Navigation group The Filament navigation group the Vaulter resources are listed under. |
| `vaulter-filament.resources` | — | — | Enabled resources Toggle which Vaulter Filament resources the plugin registers — the Drive/permissions domain (drive, node, grant, share, activity) over the unified v_nodes tree. Each can be turned off independently; every entry defaults to true when omitted. |
| `vaulter-filament.resources.drive` | — | `true` | Drive / permissions domain (M1–M4 core). |
| `vaulter-filament.resources.node` | — | `true` | — |
| `vaulter-filament.resources.grant` | — | `true` | — |
| `vaulter-filament.resources.share` | — | `true` | — |
| `vaulter-filament.resources.activity` | — | `true` | — |

### `vaulter-media`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-media.disk` | `VAULTER_DISK` | `'local'` | — |
| `vaulter-media.dedup` | — | — | tenant (default) \| drive \| off — identical bytes are never shared across tenants. |
| `vaulter-media.dedup.scope` | `VAULTER_DEDUP_SCOPE` | `'tenant'` | — |
| `vaulter-media.gc.grace_hours` | `VAULTER_GC_GRACE_HOURS` | `24` | — |
| `vaulter-media.gc.orphan_grace_hours` | `VAULTER_ORPHAN_GRACE_HOURS` | `env('VAULTER_GC_GRACE_HOURS', 24)` | — |
| `vaulter-media.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-media.schedule.blobs_gc` | — | `'dailyAt:04:00'` | — |
| `vaulter-media.schedule.blobs_reconcile` | — | `'weeklyOn:0,04:30'` | — |
| `vaulter-media.content_store` | — | `Sha256ContentStore::class` | — |
| `vaulter-media.storage_driver` | — | `FlysystemStorageDriver::class` | — |
| `vaulter-media.blob_store` | — | `BlobStore::class` | — |
<!-- vaulter:config-reference:end -->
