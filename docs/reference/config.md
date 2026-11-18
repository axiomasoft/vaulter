---
title: Configuration reference
description: Package-owned config keys — defaults, env vars, and accepted values.
outline: deep
---

# Configuration reference

Current keys of the kernel and of every installed package, by section. Each package owns one config root and
publishes its file under its own tag: `vaulter` (`vaulter-config`, the kernel), `vaulter-media`, `vaulter-drive`,
`vaulter-documents`, `vaulter-webhooks`, `vaulter-azguard` and `vaulter-filament` (`<root>-config`). A key moved
with its concept: the drive's access, sharing, profiles, features, handlers and models are under `vaulter-drive`,
the webhook settings under `vaulter-webhooks`, the blob model under `vaulter-media`. Publish everything with
`php artisan vaulter:install`.

```bash
php artisan vendor:publish --tag=vaulter-config
```

Each table column: the config path (written with its root), the env override (when
one exists), the shipped default, and what it controls. Values are read back through
the package-owned `Configuration\*Config` classes, injected into services instead of raw
`config()` calls.

::: warning Config-cache safe
Every binding is an FQCN or a plain scalar — no closures. The config is safe to
`php artisan config:cache`.
:::

## Models — `models.*`

Swappable Eloquent models, each in the config of the package that owns it. Point any entry at your own subclass to
add behaviour (ULID keys, tenant scoping, audit). Vaulter always resolves models through these maps.

| Key | Default | Owned by |
|---|---|---|
| `vaulter-drive.models.drive` | `Vaulter\Drive\Models\Drive` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.node` | `Vaulter\Drive\Models\Node` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.file` | `Vaulter\Drive\Models\File` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.file_revision` | `Vaulter\Drive\Models\FileRevision` | `axiomasoft/vaulter-drive` |
| `vaulter-media.models.blob` | `Vaulter\Media\Models\Cas\Blob` | `axiomasoft/vaulter-media` |
| `vaulter-drive.models.file_revision_blob` | `Vaulter\Drive\Models\FileRevisionBlob` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.grant` | `Vaulter\Drive\Models\Access\NodeGrant` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.node_share` | `Vaulter\Drive\Models\Sharing\NodeShare` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.upload_session` | `Vaulter\Drive\Models\Uploads\UploadSession` | `axiomasoft/vaulter-drive` |
| `vaulter-drive.models.upload_part` | `Vaulter\Drive\Models\Uploads\UploadPart` | `axiomasoft/vaulter-drive` |
| `vaulter-documents.models.node_comment` | `Vaulter\Documents\Models\Comments\NodeComment` | `axiomasoft/vaulter-documents` |
| `vaulter-documents.models.document_revision` | `Vaulter\Documents\Models\Revisions\DocumentRevision` | `axiomasoft/vaulter-documents` |
| `vaulter-drive.models.activity` | `Vaulter\Drive\Models\Activity\Activity` | `axiomasoft/vaulter-drive` |
| `vaulter.models.outbox` | `Vaulter\Events\Outbox\Models\OutboxEvent` | core |
| `vaulter-webhooks.models.webhook_endpoint` | `Vaulter\Webhooks\Models\WebhookEndpoint` | `axiomasoft/vaulter-webhooks` |
| `vaulter-webhooks.models.webhook_delivery` | `Vaulter\Webhooks\Models\WebhookDelivery` | `axiomasoft/vaulter-webhooks` |

Models are configured only under their owner's root (`vaulter-drive.models.*`, `vaulter-media.models.*`, `vaulter-documents.models.*`, `vaulter-webhooks.models.*`); the kernel's map holds only the outbox model.

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

## Profiles — `vaulter-drive.profiles.*`

| Key | Default |
|---|---|
| `vaulter-drive.profiles.providers` | `[]` |
| `vaulter-drive.profiles.default` | `Vaulter\Drive\Profiles\DefaultProfile` |

Both keys accept `ProfileProvider` FQCNs. Profile keys are unique and the default is registered.

## Features — `vaulter-drive.features.*`

Global feature toggles, read by `DriveOptions::featureEnabled()`; a panel may override any
of these per-panel.

| Key | Default | Gates |
|---|---|---|
| `vaulter-drive.features.grants` | `true` | Per-node grants (the ACL) |
| `vaulter-drive.features.activity` | `true` | The activity log |
| `vaulter-drive.features.share_links` | `false` | Bearer node share links |
| `vaulter-drive.features.public_listing` | `false` | Public listing |
| `vaulter-drive.features.changes` | `true` | The drive change journal behind `DriveHandle::changes()` |

## Document handlers — `vaulter-drive.handlers`

A list of `FileHandler` FQCNs, resolved by the drive-owned `Vaulter\Drive\Handlers\FileHandlerRegistry`
by mime → extension → capability → priority, falling back to the `DownloadOnlyHandler`
null-object. The two baseline entries are the drive's own default (resolved even
without `vaulter-documents` installed). File handlers serve files only: document nodes are
presented by their [document type](/extending/document-types).

| Provider-tagged built-ins | Owner |
|---|---|
| `Vaulter\Drive\Handlers\Builtin\PreviewHandler` | `axiomasoft/vaulter-drive` |
| `Vaulter\Drive\Handlers\Builtin\DownloadOnlyHandler` | `axiomasoft/vaulter-drive` |

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

### Drive ACL — `vaulter-drive.access.permission_driver`, `vaulter-drive.access.permission_drivers.*`

| Key | Env | Default | Accepts |
|---|---|---|---|
| `vaulter-drive.access.permission_driver` | `VAULTER_PERMISSION_DRIVER` | `database` | a key in `vaulter-drive.access.permission_drivers` |
| `vaulter-drive.access.permission_drivers.database` | — | `Vaulter\Drive\Access\Drivers\DatabasePermissionDriver` | standalone — authorises purely from grant rows |
| `vaulter-drive.access.permission_drivers.gate` | — | `Vaulter\Drive\Access\Drivers\GatePermissionDriver` | the Laravel Gate driver |
| `vaulter-drive.access.permission_drivers.spatie` | — | `Vaulter\Drive\Access\Drivers\SpatiePermissionDriver` | the spatie/laravel-permission driver |
| `vaulter-drive.access.permission_drivers.azguard` | — | `Vaulter\AzGuard\AzGuardPermissionDriver` | registered by the installed `vaulter-azguard` provider |
| `vaulter-drive.access.permission_drivers.null` | — | `Vaulter\Drive\Testing\NullPermissionDriver` | open, **non-production only** |

::: danger Null driver in production
The `null` driver is open and env-gated. Production config validation rejects a panel
bound to it — bind a real driver before deploying.
:::

### Gate driver map — `vaulter-drive.access.gate.*`

Used only when `vaulter-drive.access.permission_driver = 'gate'`. Maps a `GrantLevel` to a host Gate
ability; the highest allowed level becomes the actor's role grant.

| Key | Env | Default |
|---|---|---|
| `vaulter-drive.access.gate.abilities.reader` | — | `vaulter.view` |
| `vaulter-drive.access.gate.abilities.commenter` | — | `vaulter.comment` |
| `vaulter-drive.access.gate.abilities.writer` | — | `vaulter.edit` |
| `vaulter-drive.access.gate.abilities.organizer` | — | `vaulter.organize` |
| `vaulter-drive.access.gate.abilities.owner` | — | `vaulter.manage` |
| `vaulter-drive.access.gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `vaulter.super` |
| `vaulter-drive.access.gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `vaulter.create` |

### Spatie driver map — `vaulter-drive.access.spatie.*`

Used only when `vaulter-drive.access.permission_driver = 'spatie'` **and** spatie/laravel-permission is
installed.

| Key | Env | Default |
|---|---|---|
| `vaulter-drive.access.spatie.permissions.reader` | — | `vaulter.view` |
| `vaulter-drive.access.spatie.permissions.commenter` | — | `vaulter.comment` |
| `vaulter-drive.access.spatie.permissions.writer` | — | `vaulter.edit` |
| `vaulter-drive.access.spatie.permissions.organizer` | — | `vaulter.organize` |
| `vaulter-drive.access.spatie.permissions.owner` | — | `vaulter.manage` |
| `vaulter-drive.access.spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `vaulter.super` |
| `vaulter-drive.access.spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `vaulter.create` |
| `vaulter-drive.access.spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` |
| `vaulter-drive.access.spatie.role_subject_type` | — | `role` |
| `vaulter-drive.access.spatie.team_subject_type` | — | `team` |

`vaulter-drive.access.spatie.teams` falls back here only when the host's own `config('permission.teams')`
is unset. `role_subject_type`/`team_subject_type` are the morph aliases role/team
grant rows are addressed to.

### AzGuard driver map — `vaulter-azguard.*`

Separate package: `composer require axiomasoft/vaulter-azguard`. The settings below
come from `config/vaulter-azguard.php`, rather than core configuration.

| Key | Default |
|---|---|
| `vaulter-azguard.panel_id` | `null` (resolve the panel from the permission key) |

Used only when `vaulter-drive.access.permission_driver = 'azguard'`. Action-centric — maps a Vaulter
`Ability` to an AzGuard permission key.

| Key | Default |
|---|---|
| `vaulter-azguard.permissions.view` | `documents.view` |
| `vaulter-azguard.permissions.download` | `documents.download` |
| `vaulter-azguard.permissions.comment` | `documents.comment` |
| `vaulter-azguard.permissions.edit` | `documents.update` |
| `vaulter-azguard.permissions.create` | `documents.create` |
| `vaulter-azguard.permissions.move` | `documents.update` |
| `vaulter-azguard.permissions.copy` | `documents.update` |
| `vaulter-azguard.permissions.trash` | `documents.delete` |
| `vaulter-azguard.permissions.delete` | `documents.delete` |
| `vaulter-azguard.permissions.restore` | `documents.delete` |
| `vaulter-azguard.permissions.share` | `documents.manage` |
| `vaulter-azguard.permissions.manage` | `documents.manage` |

| Key | Env | Default |
|---|---|---|
| `vaulter-azguard.create_permission` | `VAULTER_AZGUARD_CREATE_PERMISSION` | `documents.create` |

The bridge supplies the real guard adapter. The host must implement `AzGuardBackedUser`
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
| `vaulter-media.disk` | `VAULTER_DISK` | `local` | The default Flysystem disk for canonical CAS objects when a blob's `v_drives.disk` doesn't pin its own |

::: warning Keep the CAS bucket unversioned
Never use S3 lifecycle/versioning as a blob-deletion mechanism.
:::

## Sharing

### Bearer node share links — `vaulter-drive.sharing.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `vaulter-drive.sharing.token_generator` | — | `Vaulter\Drive\Support\RandomShareTokenGenerator` | The `ShareTokenGenerator` minting the raw bearer token (only its sha256 hash is stored) |
| `vaulter-drive.sharing.url_base` | `VAULTER_SHARE_URL_BASE` | `/share` | The public base a share URL is built from |
| `vaulter-drive.sharing.prefix_length` | — | `8` | How many leading chars of the raw token are stored as the non-secret `token_prefix` |

See [Usage → Sharing](/guides/sharing).

## Trash — `vaulter-drive.trash.retention_days`

| Key | Env | Default |
|---|---|---|
| `vaulter-drive.trash.retention_days` | `VAULTER_TRASH_RETENTION_DAYS` | `30` |

The retention window before a trashed node's subtree is permanently force-deleted by
`php artisan vaulter:trash:purge`.

## Drives — `vaulter-drive.drives.*`

| Key | Env | Default | Controls |
|---|---|---|---|
| `vaulter-drive.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | `HasDrives`: deleting the owner model deletes its drives (system actor `owner-deleted`); with `SoftDeletes` only `forceDeleted` counts |

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

## Reliability — webhooks — `vaulter-webhooks.*`

The separate package `axiomasoft/vaulter-webhooks` (opt-in module `webhooks`; config file `config/vaulter-webhooks.php`,
tag `vaulter-webhooks-config`).

A transactional outbox + [Standard Webhooks](/guides/webhooks#headers-and-signature) signed delivery
(at-least-once, a retry schedule, DLQ).

| Key | Env | Default | Controls |
|---|---|---|---|
| `vaulter-webhooks.timeout` | — | `15` | HTTP request timeout (seconds) per delivery POST |
| `vaulter-webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Seconds to wait after the n-th failed attempt; attempts = entries + 1 (about three days), the last failure makes the delivery dead |
| `vaulter-webhooks.retry_jitter` | — | `0.1` | Fraction (0–0.5) added or removed at random from each retry delay |
| `vaulter-webhooks.disable_after_days` | — | `5` | Days of unbroken failure after which an endpoint is switched off (`0` = never) |
| `vaulter-webhooks.secret_rotation_overlap` | — | `86400` | Seconds the previous secret keeps signing after a rotation (at most 604800) |
| `vaulter-webhooks.retention_days` | — | `30` | Age past which terminal deliveries are prunable (pending/failed ones never are) |
| `vaulter-webhooks.relay.fanout_chunk` | — | `100` | Endpoints written per fan-out transaction (integer 1..1000; anything else fails boot) |
| `vaulter-webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `vaulter.webhooks.manage` | The Gate ability gating the endpoint-management surface; `null` allows any authenticated user |

The opt-in `vaulter.modules.webhooks` switches the `webhooks.fanout` outbox consumer and the endpoint routes; the tables always exist. See [Recipes → Reliability & webhooks](/guides/recipes).

## Outbox — `outbox.*`

The transactional outbox every event is written to, and its relay (`vaulter:relay`); see
[The outbox](/concepts/events-outbox). Every value is a positive integer; anything else fails boot.

| Key | Env | Default | Controls |
|---|---|---|---|
| `outbox.settle_window_seconds` | — | `300` | Reconciliation trusts only events older than this; keep it above the longest Vaulter transaction (`vaulter:doctor` compares it with the database limits) |
| `outbox.retention_seconds` | — | `604800` | Age past which an event no open delivery and no consumer holds is pruned |
| `outbox.max_consumer_hold_seconds` | — | `604800` | How long a paused durable consumer holds retention before it is detached with a gap (at least the settle window) |
| `outbox.dead_retention_seconds` | — | `2592000` | How long a dead delivery is kept for inspection and replay |
| `outbox.lease_seconds` | — | `60` | How long a relay worker owns a leased delivery (and a reconciler its consumer) before a takeover |
| `outbox.batch` | — | `100` | Deliveries one relay pass leases per consumer |
| `outbox.max_attempts` | — | `10` | Attempts after which a retried delivery is dead |
| `outbox.backoff_base_seconds` | — | `2` | Base of the full-jitter retry delay |
| `outbox.backoff_cap_seconds` | — | `3600` | Cap of the full-jitter retry delay (at least the base) |

## Change journal — `vaulter-drive.changes.*`

The per-drive [change journal](/concepts/events) syncing clients read through `DriveHandle::changes()`.
`vaulter-drive.features.changes` (default `true`) switches the writing of entries; the tables always exist.
Turning it off and on requires an owner bump of `key_generation` on every instance and a full client bootstrap; do not reuse cursors across the gap.

| Key | Env | Default | Controls |
|---|---|---|---|
| `vaulter-drive.changes.retention_days` | — | `30` | Days an entry stays readable; `vaulter:changes:prune` deletes older ones and a cursor behind them expires (`410 cursor_expired`). Integer ≥ 1, anything else fails boot |
| `vaulter-drive.changes.max_page_size` | — | `1000` | The largest `limit` of one `changes()->list()` page (integer 1..10000, anything else fails boot) |

## Scheduling — `schedule.*`

Which maintenance commands the packages put on Laravel's scheduler and how often; the command
list and the value grammar are in [Commands → Scheduling](/reference/commands#scheduling).

| Key | Env | Default | Controls |
|---|---|---|---|
| `schedule.enabled` | `VAULTER_SCHEDULE_ENABLED` | `true` | Master switch for every Vaulter scheduled task (core, drive, media, webhooks); set `false` when the host schedules the commands itself. Must be a boolean |
| `schedule.outbox_relay` | — | `'everyMinute'` | `vaulter:relay`; `null` turns it off |
| `schedule.outbox_prune` | — | `'hourly'` | `vaulter:outbox:prune`; `null` turns it off |
| `vaulter-webhooks.schedule.webhooks_retry` | — | `'everyMinute'` | `vaulter:webhooks:retry`; `null` turns it off |
| `vaulter-webhooks.schedule.webhooks_prune` | — | `'dailyAt:03:10'` | `vaulter:webhooks:prune`; `null` turns it off |
| `vaulter-drive.schedule.changes_prune` | — | `'dailyAt:03:20'` | `vaulter:changes:prune`; `null` turns it off |
| `vaulter-drive.schedule.*` | — | `uploads_sweep` `hourlyAt:15`, `trash_purge` `dailyAt:03:30`, `drives_purge` `hourlyAt:25`, `revisions_prune` `dailyAt:03:40`, `scan_reconcile` `everyFiveMinutes`, `drives_recount` `null` | Drive tasks; `scan_reconcile` runs only while the scan mode is not `off` and does not depend on `vaulter.modules.webhooks` |
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

## Collaboration {#collaboration}

Commercial add-on operations are documented in its private guide.

## See also

- [Facade](/reference/php-api) — the runtime entry points
- [Contracts reference](/reference/php-api) — the interfaces these FQCNs implement
- [Commands reference](/reference/commands) — the schedulable maintenance commands

## Additional settings

| Key | Default |
|---|---|
| `vaulter-documents.comments.enabled` | `true` |
| `vaulter.modules.collaboration` | `false` (opt-in; [Realtime Collaboration](/guides/collaboration)) |
| `vaulter-documents.tiptap.extra_nodes` | `[]` |
| `vaulter-documents.tiptap.extra_marks` | `[]` |
| `vaulter-documents.tiptap.extra_attributes` | `[]` |
| `vaulter-documents.tiptap.url_attributes` | `[]` |
| `vaulter-media.gc.orphan_grace_hours` | `24` (`VAULTER_ORPHAN_GRACE_HOURS`, fallback `VAULTER_GC_GRACE_HOURS`) |
| `ids.host_keys` | `string` (`VAULTER_HOST_KEYS`) |
| `vaulter-drive.listing.scan_budget` | `1000` |
| `routes.middleware.authenticated` | `['api', 'auth:sanctum']` |
| `routes.middleware.public` | `['api']` |
| `routes.middleware.hub` | `['api']` |
| `routes.rate_limits.public` | `60` (`VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE`) |
| `routes.rate_limits.uploads` | `120` (`VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE`) |
| `vaulter-drive.sharing.default_ttl_hours` | `72` (`VAULTER_SHARE_DEFAULT_TTL_HOURS`) |
| `vaulter-drive.sharing.max_ttl_hours` | `720` (`VAULTER_SHARE_MAX_TTL_HOURS`) |
| `vaulter-drive.downloads.inline_mime_types` | `['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain']` |
| `observability.enabled` | `false` (`VAULTER_OBSERVABILITY_ENABLED`) |
| `observability.recorder` | `null` (`VAULTER_OBSERVABILITY_RECORDER`) |
| `observability.tracing.enabled` | `false` (`VAULTER_TRACING_ENABLED`) |
| `observability.tracing.recorder` | `null` (`VAULTER_TRACING_RECORDER`) |
| `vaulter-webhooks.retry_claim_seconds` | `60` |
| `vaulter-webhooks.delivery.url_guard` | `Vaulter\Webhooks\DefaultOutboundUrlGuard` |
| `vaulter-webhooks.delivery.allowed_schemes` | `['http', 'https']` |
| `vaulter-webhooks.delivery.blocked_ip_ranges` | `null` (closed built-in ranges) |
| `vaulter-webhooks.delivery.allowed_ip_ranges` | `[]` |
| `vaulter-webhooks.delivery.connect_timeout` | `2` seconds |
| `vaulter-webhooks.delivery.max_response_bytes` | `65536` bytes |

Documents are enabled when their package is installed; blobs and file revisions are always enabled.
Comments default to true; set `vaulter-documents.comments.enabled = false` to turn them off.

## All keys (generated)

<!-- vaulter:config-reference:start -->
### `vaulter-azguard`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-azguard.panel_id` | `VAULTER_AZGUARD_PANEL` | `null` | AzGuard panel The AzGuard v1 panel the adapter passes as `guard:` to every check. null (the default) lets AzGuard select the panel — by a full name (`app:documents.view`) or a panel prefix in the permission, else the panel of the request, else the panel of the user model. The default map below names local keys (`documents.view`) of that panel, so the panel's resource prefix must not be `documents` (AzGuard refuses such a panel with PrefixConflictException): give the panel another resourcePrefix() or map the abilities below to other permissions. Set an explicit panel id when the user model belongs to several panels or the checks must not depend on the request panel; AzGuard throws when no panel can be selected. |
| `vaulter-azguard.guard` | — | `null` | Explicit guard A class implementing `Vaulter\AzGuard\Contracts\AzGuardGuard` that answers for the required `$actor` argument of each check. It must not substitute the request user or switch authentication state. null (the default) uses the actor itself when it is a guard, else an adapter over the actor when it is AzGuard-backed, else the lane passes through and grants nothing. |
| `vaulter-azguard.permissions` | — | — | A role is granted only when every ability of its registered version has a mapping and the actor holds every mapped permission. Missing mappings never imply permission. |
| `vaulter-azguard.permissions.view` | — | `'documents.view'` | — |
| `vaulter-azguard.permissions.download` | — | `'documents.download'` | — |
| `vaulter-azguard.permissions.comment` | — | `'documents.comment'` | — |
| `vaulter-azguard.permissions.edit` | — | `'documents.update'` | — |
| `vaulter-azguard.permissions.create` | — | `'documents.create'` | — |
| `vaulter-azguard.permissions.move` | — | `'documents.update'` | — |
| `vaulter-azguard.permissions.copy` | — | `'documents.update'` | — |
| `vaulter-azguard.permissions.trash` | — | `'documents.delete'` | — |
| `vaulter-azguard.permissions.delete` | — | `'documents.delete'` | — |
| `vaulter-azguard.permissions.restore` | — | `'documents.delete'` | — |
| `vaulter-azguard.permissions.share` | — | `'documents.manage'` | — |
| `vaulter-azguard.permissions.manage` | — | `'documents.manage'` | — |
| `vaulter-azguard.create_permission` | `VAULTER_AZGUARD_CREATE_PERMISSION` | `'documents.create'` | — |

### `vaulter-collaboration`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-collaboration.hub.url` | `VAULTER_COLLAB_URL` | `'ws://localhost:1234'` | — |
| `vaulter-collaboration.hub.jwt_keys` | `VAULTER_COLLAB_JWT_KEYS` | `null` | The room-token keys (Laravel only, never given to the hub): `kid => secret`, HS256, each at least 32 bytes and different from the webhook secret the hub holds. The environment value is "kid:secret" pairs, comma-separated; a PHP array is accepted too. Tokens are signed with jwt_active_kid and verified with any key of the set: add the new key, switch the active kid, remove the old key once its tokens have expired (docs/key-rotation.md). |
| `vaulter-collaboration.hub.jwt_active_kid` | `VAULTER_COLLAB_JWT_ACTIVE_KID` | `null` | — |
| `vaulter-collaboration.hub.issuer` | `VAULTER_COLLAB_ISSUER` | `null` | The `iss` of a room token, compared as an exact string with the server configuration (null: app.url). |
| `vaulter-collaboration.hub.webhook_secret` | `VAULTER_COLLAB_WEBHOOK_SECRET` | `null` | — |
| `vaulter-collaboration.hub.token_ttl` | — | `300` | The lifetime of a room token (seconds, at most 300): the longest a revoked writer keeps a session when no control message reaches the hub, because a renewal checks the rights again. |
| `vaulter-collaboration.hub.clock_skew` | — | `30` | The clock difference tolerated on a room token's exp, by the hub and the backend alike (seconds, 0..30); part of the exposure bound token_ttl + clock_skew. Not the tolerance of a request signature (timestamp_tolerance). |
| `vaulter-collaboration.hub.timestamp_tolerance` | — | `300` | — |
| `vaulter-collaboration.control` | — | — | How a change of effective permissions (drive.access.effective_permissions_changed) reaches the hubs at once: `redis` publishes on the channel vaulter:collab:control of the connection below, `http` POSTs to the management control endpoint of every hub listed (signed with hub.webhook_secret), `none` sends nothing — the hubs then learn of it on the next append or renewal. A lost message is never replayed: the token lifetime bounds the exposure. |
| `vaulter-collaboration.control.transport` | `VAULTER_COLLAB_CONTROL_TRANSPORT` | `'none'` | — |
| `vaulter-collaboration.control.redis.connection` | `VAULTER_COLLAB_CONTROL_REDIS` | `'default'` | — |
| `vaulter-collaboration.control.http.urls` | `VAULTER_COLLAB_CONTROL_URLS` | `''` | The management base URLs of the hubs (comma-separated in the environment), e.g. http://hub-1:1235. |
| `vaulter-collaboration.control.http.timeout` | — | `2` | — |
| `vaulter-collaboration.max_state_bytes` | — | `16777216` | The largest binary Yjs state one hub store may write (413 above it). |
| `vaulter-collaboration.mode` | `VAULTER_COLLAB_MODE` | `'buffered'` | When an edit counts as saved (protocol 2): `buffered` — the hub may sync it before the backend committed it; `durable` — the hub acknowledges an edit only with the backend receipt. |
| `vaulter-collaboration.limits.update_bytes` | — | `1048576` | The largest decoded Yjs update one append carries, and the largest append request body (413 above either, checked before the body is decoded). |
| `vaulter-collaboration.limits.request_bytes` | — | `2097152` | — |
| `vaulter-collaboration.compaction` | — | — | The hub's compaction worker leases a document with uncheckpointed edits once its log holds this many updates, this many bytes of them, or its oldest one is this many seconds old; a drain request always qualifies. A lease lasts lease_ttl seconds. |
| `vaulter-collaboration.compaction.trigger_updates` | — | `500` | — |
| `vaulter-collaboration.compaction.trigger_bytes` | — | `1048576` | — |
| `vaulter-collaboration.compaction.trigger_age` | — | `600` | — |
| `vaulter-collaboration.compaction.lease_ttl` | — | `60` | — |
| `vaulter-collaboration.drain.restore_deadline` | — | `10` | How long a restore, REST replace, content read or export of a document with uncheckpointed edits waits for the hub to compact them (seconds); after it the request answers 409 document_state_pending. |
| `vaulter-collaboration.drain.cache_store` | — | `null` | The cache store of the draining flag vaulter:collaboration:drain sets (null: the default store). Every PHP process must see it, so not an `array` or `null` store (the doctor reports one as an error). |
| `vaulter-collaboration.drain.max_append_tx` | — | `5` | The longest an append transaction lasts, lock waits included (seconds): a drain reports its outcome no earlier than this after it set the flag, so an append that read the flag before it was set has committed by then. |
| `vaulter-collaboration.retention.retired_generation_days` | — | `7` | Days the log and receipts of a closed generation stay after its last token and retry could still arrive. |
| `vaulter-collaboration.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-collaboration.schedule.collaboration_prune` | — | `'dailyAt:03:40'` | — |

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
| `vaulter.security` | — | — | id => nonempty reason: production invariants a host knowingly accepts (ADR-0036); never a structural error. |
| `vaulter.security.accepted_risks` | — | `[]` | — |
| `vaulter.modules` | — | `[]` | Module activation overrides: module id => true\|false. Auto modules (media, drive, documents…) are active unless switched off; opt-in modules (webhooks) only when switched on. |
| `vaulter.routes.prefix` | `VAULTER_ROUTE_PREFIX` | `'api/vaulter'` | — |
| `vaulter.routes.middleware.authenticated` | — | `['api', 'auth:sanctum']` | — |
| `vaulter.routes.middleware.public` | — | `['api']` | — |
| `vaulter.routes.middleware.hub` | — | `['api']` | — |
| `vaulter.routes.rate_limits.public` | `VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE` | `60` | — |
| `vaulter.routes.rate_limits.uploads` | `VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE` | `120` | — |
| `vaulter.outbox` | — | — | The transactional outbox: every domain event is written to v_outbox in the transaction of its operation; vaulter:relay hands it to each outbox consumer (webhooks.fanout, …) at least once. settle_window_seconds must exceed the longest Vaulter transaction: reconciliation only trusts events older than it, so a long transaction committing a smaller seq later is not skipped. |
| `vaulter.outbox.settle_window_seconds` | — | `300` | — |
| `vaulter.outbox.retention_seconds` | — | `604800` | — |
| `vaulter.outbox.max_consumer_hold_seconds` | — | `604800` | How long a paused consumer (switched off, missing from the module graph) holds retention before it is detached with an explicit gap. |
| `vaulter.outbox.dead_retention_seconds` | — | `2592000` | — |
| `vaulter.outbox.lease_seconds` | — | `60` | — |
| `vaulter.outbox.batch` | — | `100` | — |
| `vaulter.outbox.max_attempts` | — | `10` | — |
| `vaulter.outbox.backoff_base_seconds` | — | `2` | — |
| `vaulter.outbox.backoff_cap_seconds` | — | `3600` | — |
| `vaulter.idempotency` | — | — | Idempotency-Key receipts: how long a retry with the same key gets the stored outcome. vaulter:idempotency:prune deletes expired receipts. |
| `vaulter.idempotency.ttl_seconds` | — | `86400` | — |
| `vaulter.schedule` | — | — | Maintenance tasks put on the host scheduler. Value: null (off) \| everyMinute \| everyFiveMinutes hourly \| daily \| weekly \| 'dailyAt:HH:MM' \| 'hourlyAt:M' \| 'weeklyOn:D,HH:MM' \| a 5-field cron expression. Set enabled to false when the host schedules the commands itself. |
| `vaulter.schedule.enabled` | `VAULTER_SCHEDULE_ENABLED` | `true` | — |
| `vaulter.schedule.outbox_relay` | — | `'everyMinute'` | — |
| `vaulter.schedule.outbox_prune` | — | `'hourly'` | — |
| `vaulter.schedule.idempotency_prune` | — | `'dailyAt:03:30'` | — |
| `vaulter.observability.enabled` | `VAULTER_OBSERVABILITY_ENABLED` | `false` | — |
| `vaulter.observability.recorder` | `VAULTER_OBSERVABILITY_RECORDER` | `null` | — |
| `vaulter.observability.tracing.enabled` | `VAULTER_TRACING_ENABLED` | `false` | — |
| `vaulter.observability.tracing.recorder` | `VAULTER_TRACING_RECORDER` | `null` | — |
| `vaulter.models.outbox` | — | `OutboxEvent::class` | — |

### `vaulter-documents`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-documents.types` | — | `[RichTextDocumentType::class, MarkdownDocumentType::class]` | Document type classes; add SpreadsheetDocumentType or your own here. |
| `vaulter-documents.default_type` | — | `'vaulter/rich-text'` | — |
| `vaulter-documents.collaboration.session_ttl` | — | `300` | — |
| `vaulter-documents.content.max_size_kb` | — | `5120` | — |
| `vaulter-documents.revisions.limit` | — | `50` | — |
| `vaulter-documents.revisions.checkpoint_interval_seconds` | — | `300` | — |
| `vaulter-documents.comments.enabled` | — | `true` | — |
| `vaulter-documents.exporters` | — | `[HtmlExporter::class, MarkdownExporter::class]` | Document exporter classes, resolved by (document type, format). |
| `vaulter-documents.tiptap.extra_nodes` | — | `[]` | — |
| `vaulter-documents.tiptap.extra_marks` | — | `[]` | — |
| `vaulter-documents.tiptap.extra_attributes` | — | `[]` | — |
| `vaulter-documents.tiptap.url_attributes` | — | `[]` | — |
| `vaulter-documents.broadcast` | — | — | The private broadcast channels of a document ("<prefix>.<nodeId>"): the comment events use them, and the collaboration package authorizes them and names its rooms with the same prefix. |
| `vaulter-documents.broadcast.channel_prefix` | — | `'vaulter.documents'` | — |
| `vaulter-documents.models.document_revision` | — | `DocumentRevision::class` | — |
| `vaulter-documents.models.node_comment` | — | `NodeComment::class` | — |

### `vaulter-drive`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-drive.profiles.default` | — | `DefaultProfile::class` | — |
| `vaulter-drive.profiles.providers` | — | `[]` | — |
| `vaulter-drive.scan.mode` | — | `null` | — |
| `vaulter-drive.scan.required` | — | `null` | — |
| `vaulter-drive.scan.scanner` | — | `null` | — |
| `vaulter-drive.scan.scanner_key` | — | `null` | — |
| `vaulter-drive.scan.scanner_version` | — | `null` | — |
| `vaulter-drive.scan.policy_version` | — | `null` | — |
| `vaulter-drive.scan.rollout_key` | — | `null` | — |
| `vaulter-drive.features.grants` | — | `true` | — |
| `vaulter-drive.features.activity` | — | `true` | — |
| `vaulter-drive.features.changes` | — | `true` | The drive change journal behind DriveHandle::changes(). |
| `vaulter-drive.features.share_links` | — | `false` | — |
| `vaulter-drive.features.public_listing` | — | `false` | — |
| `vaulter-drive.drives.delete_with_owner` | `VAULTER_DRIVES_DELETE_WITH_OWNER` | `false` | HasDrives: delete the drives of an owner when the owner is deleted. |
| `vaulter-drive.access.permission_driver` | `VAULTER_PERMISSION_DRIVER` | `'database'` | — |
| `vaulter-drive.access.permission_drivers.database` | — | `DatabasePermissionDriver::class` | — |
| `vaulter-drive.access.permission_drivers.gate` | — | `GatePermissionDriver::class` | — |
| `vaulter-drive.access.permission_drivers.spatie` | — | `SpatiePermissionDriver::class` | — |
| `vaulter-drive.access.permission_drivers.null` | — | `NullPermissionDriver::class` | — |
| `vaulter-drive.access.gate.abilities.previewer` | — | `'vaulter.preview'` | — |
| `vaulter-drive.access.gate.abilities.reader` | — | `'vaulter.view'` | — |
| `vaulter-drive.access.gate.abilities.commenter` | — | `'vaulter.comment'` | — |
| `vaulter-drive.access.gate.abilities.writer` | — | `'vaulter.edit'` | — |
| `vaulter-drive.access.gate.abilities.organizer` | — | `'vaulter.organize'` | — |
| `vaulter-drive.access.gate.abilities.owner` | — | `'vaulter.manage'` | — |
| `vaulter-drive.access.gate.super_ability` | `VAULTER_GATE_SUPER_ABILITY` | `'vaulter.super'` | — |
| `vaulter-drive.access.gate.create_ability` | `VAULTER_GATE_CREATE_ABILITY` | `'vaulter.create'` | — |
| `vaulter-drive.access.spatie.permissions.previewer` | — | `'vaulter.preview'` | — |
| `vaulter-drive.access.spatie.permissions.reader` | — | `'vaulter.view'` | — |
| `vaulter-drive.access.spatie.permissions.commenter` | — | `'vaulter.comment'` | — |
| `vaulter-drive.access.spatie.permissions.writer` | — | `'vaulter.edit'` | — |
| `vaulter-drive.access.spatie.permissions.organizer` | — | `'vaulter.organize'` | — |
| `vaulter-drive.access.spatie.permissions.owner` | — | `'vaulter.manage'` | — |
| `vaulter-drive.access.spatie.super_permission` | `VAULTER_SPATIE_SUPER_PERMISSION` | `'vaulter.super'` | — |
| `vaulter-drive.access.spatie.create_permission` | `VAULTER_SPATIE_CREATE_PERMISSION` | `'vaulter.create'` | — |
| `vaulter-drive.access.spatie.teams` | `VAULTER_SPATIE_TEAMS` | `false` | — |
| `vaulter-drive.access.spatie.role_subject_type` | — | `'role'` | — |
| `vaulter-drive.access.spatie.team_subject_type` | — | `'team'` | — |
| `vaulter-drive.listing.scan_budget` | — | `1000` | — |
| `vaulter-drive.sharing.token_generator` | — | `RandomShareTokenGenerator::class` | — |
| `vaulter-drive.sharing.url_base` | `VAULTER_SHARE_URL_BASE` | `'/share'` | — |
| `vaulter-drive.sharing.prefix_length` | — | `8` | — |
| `vaulter-drive.sharing.default_ttl_hours` | `VAULTER_SHARE_DEFAULT_TTL_HOURS` | `72` | — |
| `vaulter-drive.sharing.max_ttl_hours` | `VAULTER_SHARE_MAX_TTL_HOURS` | `720` | — |
| `vaulter-drive.downloads.delivery_mode` | — | `'strict'` | — |
| `vaulter-drive.downloads.direct_ttl_seconds` | — | `60` | — |
| `vaulter-drive.downloads.inline_mime_types` | — | `['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain']` | — |
| `vaulter-drive.changes.retention_days` | — | `30` | Days a journal entry stays readable; vaulter:changes:prune deletes older ones and a cursor behind them expires (410 cursor_expired). |
| `vaulter-drive.changes.max_page_size` | — | `1000` | The largest page DriveHandle::changes()->list() returns. |
| `vaulter-drive.changes.key_generation` | — | `1` | Raise on every instance together to expire all cursors and sync keys (after key rotation or re-enabling the journal); clients bootstrap again. |
| `vaulter-drive.changes.cursor_max_age_seconds` | — | `86400` | Seconds a cursor lives after startCursor(): 1..2592000, at most the retention. |
| `vaulter-drive.handlers` | — | `[]` | — |
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
| `vaulter-drive.uploads.resumable.complete_timeout_seconds` | `VAULTER_UPLOAD_COMPLETE_TIMEOUT_SECONDS` | `60` | Finite monotonic budget of one completion request, DB retries included (1..300 s). |
| `vaulter-drive.uploads.resumable.max_active_sessions_per_actor` | `VAULTER_UPLOAD_MAX_ACTIVE_SESSIONS` | `20` | — |
| `vaulter-drive.uploads.resumable.metadata.allowed_keys` | — | `['filename', 'filetype', 'name', 'mime_type']` | — |
| `vaulter-drive.uploads.resumable.metadata.max_value_length` | — | `512` | — |
| `vaulter-drive.trash.retention_days` | — | `30` | — |
| `vaulter-drive.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-drive.schedule.changes_prune` | — | `'dailyAt:03:20'` | — |
| `vaulter-drive.schedule.uploads_sweep` | — | `'hourlyAt:15'` | — |
| `vaulter-drive.schedule.trash_purge` | — | `'dailyAt:03:30'` | — |
| `vaulter-drive.schedule.drives_purge` | — | `'hourlyAt:25'` | — |
| `vaulter-drive.schedule.revisions_prune` | — | `'dailyAt:03:40'` | — |
| `vaulter-drive.schedule.scan_reconcile` | — | `'everyFiveMinutes'` | — |
| `vaulter-drive.schedule.drives_recount` | — | `null` | Report-only drift check; off until an operator wants it. |
| `vaulter-drive.models.drive` | — | `Drive::class` | — |
| `vaulter-drive.models.node` | — | `Node::class` | — |
| `vaulter-drive.models.file` | — | `File::class` | — |
| `vaulter-drive.models.scan_obligation` | — | `ScanObligation::class` | — |
| `vaulter-drive.models.scan_request` | — | `ScanRequest::class` | — |
| `vaulter-drive.models.scan_evidence` | — | `ScanEvidenceRecord::class` | — |
| `vaulter-drive.models.scan_policy_state` | — | `ScanPolicyState::class` | — |
| `vaulter-drive.models.scan_backfill_run` | — | `ScanBackfillRun::class` | — |
| `vaulter-drive.models.file_revision` | — | `FileRevision::class` | — |
| `vaulter-drive.models.file_revision_blob` | — | `FileRevisionBlob::class` | — |
| `vaulter-drive.models.grant` | — | `NodeGrant::class` | — |
| `vaulter-drive.models.node_share` | — | `NodeShare::class` | — |
| `vaulter-drive.models.activity` | — | `Activity::class` | — |
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
| `vaulter-media.scrub` | — | — | The integrity scrub (vaulter:blobs:scrub): the sample and budget of one run — the first one used up ends it. Reading a whole object store is expensive, so a run covers a sample; null: no byte or time budget. Not scheduled. |
| `vaulter-media.scrub.limit` | `VAULTER_SCRUB_LIMIT` | `100` | — |
| `vaulter-media.scrub.max_bytes` | `VAULTER_SCRUB_MAX_BYTES` | `null` | — |
| `vaulter-media.scrub.max_seconds` | `VAULTER_SCRUB_MAX_SECONDS` | `null` | — |
| `vaulter-media.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-media.schedule.blobs_gc` | — | `'dailyAt:04:00'` | — |
| `vaulter-media.schedule.blobs_reconcile` | — | `'weeklyOn:0,04:30'` | — |
| `vaulter-media.content_store` | — | `Sha256ContentStore::class` | — |
| `vaulter-media.storage_driver` | — | `FlysystemStorageDriver::class` | — |
| `vaulter-media.blob_store` | — | `BlobStore::class` | — |
| `vaulter-media.models` | — | — | Swappable Eloquent model of the blob table (must extend the package model). |
| `vaulter-media.models.blob` | — | `Blob::class` | — |

### `vaulter-webhooks`

| Key | Env | Default | Description |
|---|---|---|---|
| `vaulter-webhooks.timeout` | — | `15` | — |
| `vaulter-webhooks.retry_schedule` | — | `[5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]` | Seconds to wait after the n-th failed attempt; attempts = entries + 1 (about three days in total). Jitter is the +/- fraction applied to each delay. |
| `vaulter-webhooks.retry_jitter` | — | `0.1` | — |
| `vaulter-webhooks.disable_after_days` | — | `5` | Days of uninterrupted failure before an endpoint is disabled; 0 never disables. |
| `vaulter-webhooks.secret_rotation_overlap` | — | `86400` | Seconds the previous secret keeps signing after a rotation (max 604800). |
| `vaulter-webhooks.retention_days` | — | `30` | — |
| `vaulter-webhooks.retry_claim_seconds` | — | `60` | — |
| `vaulter-webhooks.relay` | — | — | The webhooks.fanout outbox consumer materializes deliveries fanout_chunk endpoints at a time (1..1000), each chunk in its own transaction under the outbox delivery lease. |
| `vaulter-webhooks.relay.fanout_chunk` | — | `100` | — |
| `vaulter-webhooks.manage_ability` | `VAULTER_WEBHOOKS_MANAGE_ABILITY` | `'vaulter.webhooks.manage'` | This ability authorizes management; strict tenancy still limits it to the current tenant's endpoints. Endpoints without a tenant need an explicit administrative migration before a tenant can manage them. |
| `vaulter-webhooks.delivery.url_guard` | — | `DefaultOutboundUrlGuard::class` | — |
| `vaulter-webhooks.delivery.allowed_schemes` | — | `['http', 'https']` | — |
| `vaulter-webhooks.delivery.blocked_ip_ranges` | — | `null` | — |
| `vaulter-webhooks.delivery.allowed_ip_ranges` | — | `[]` | — |
| `vaulter-webhooks.delivery.connect_timeout` | — | `2` | — |
| `vaulter-webhooks.delivery.max_response_bytes` | — | `65536` | — |
| `vaulter-webhooks.schedule` | — | — | Same value grammar and vaulter.schedule.enabled switch as the core schedule keys. |
| `vaulter-webhooks.schedule.webhooks_retry` | — | `'everyMinute'` | — |
| `vaulter-webhooks.schedule.webhooks_prune` | — | `'dailyAt:03:10'` | — |
| `vaulter-webhooks.models.webhook_endpoint` | — | `WebhookEndpoint::class` | — |
| `vaulter-webhooks.models.webhook_delivery` | — | `WebhookDelivery::class` | — |
<!-- vaulter:config-reference:end -->


PLAN4-D20: `vaulter-drive.changes.key_generation` = `1` (positive integer); raise on all instances after feature-off/on or key rotation. `vaulter-drive.changes.cursor_max_age_seconds` = `86400`, range 1..min(2592000, retention_days × 86400). The cursor retains its original issue time; paging cannot extend its deadline.
