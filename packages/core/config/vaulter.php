<?php

declare(strict_types=1);

use Vaulter\Cas\BlobStore;
use Vaulter\Cas\FlysystemStorageDriver;
use Vaulter\Cas\Sha256ContentStore;
use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Collaboration\ReverbCollaborationDriver;
use Vaulter\Documents\Handlers\DownloadOnlyHandler;
use Vaulter\Documents\Handlers\PreviewHandler;
use Vaulter\Documents\Handlers\TipTapHandler;
use Vaulter\Documents\Handlers\UniverHandler;
use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Drivers\Permission\GatePermissionResolver;
use Vaulter\Drivers\Permission\SpatiePermissionResolver;
use Vaulter\Export\HtmlExporter;
use Vaulter\Export\MarkdownExporter;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentComment;
use Vaulter\Models\Documents\DocumentFolder;
use Vaulter\Models\Documents\DocumentPermission;
use Vaulter\Models\Documents\DocumentShare;
use Vaulter\Models\Documents\DocumentVersion;
use Vaulter\Models\Documents\NodeDocumentVersion;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\FileVersionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Models\Uploads\UploadPart;
use Vaulter\Models\Uploads\UploadSession;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Panels\DefaultStoragePanel;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;
use Vaulter\Support\DefaultStorageAdapter;
use Vaulter\Support\RandomShareTokenGenerator;
use Vaulter\Testing\NullRoleDriver;

/**
 * Vaulter configuration.
 *
 * Publish: php artisan vendor:publish --tag=vaulter-config
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Eloquent models (swappable)
    |--------------------------------------------------------------------------
    | Point these at your own subclasses to add behaviour (ULID keys, tenant
    | scoping, audit). Vaulter always resolves models through this map.
    */
    'models' => [
        'document' => Document::class,
        'version' => DocumentVersion::class,
        'folder' => DocumentFolder::class,
        'permission' => DocumentPermission::class,
        'comment' => DocumentComment::class,
        'share' => DocumentShare::class,

        // Vaulter Drive entities (M1a).
        'storage' => Storage::class,
        'node' => Node::class,
        'file' => File::class,
        'file_version' => FileVersion::class,
        'blob' => Blob::class,
        'file_version_blob' => FileVersionBlob::class,
        'grant' => NodeGrant::class,
        'node_share' => NodeShare::class,

        // DocDrive (M4a) — the NODE-attached document version (§2.5), distinct from
        // the legacy 'version' (document_id-attached) entry above.
        'node_document_version' => NodeDocumentVersion::class,

        'upload_session' => UploadSession::class,
        'upload_part' => UploadPart::class,
        'activity' => Activity::class,

        // Reliability layer (M3b / §2.11) — transactional outbox + webhooks.
        'outbox' => Outbox::class,
        'webhook_endpoint' => WebhookEndpoint::class,
        'webhook_delivery' => WebhookDelivery::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table names (per-entity override)
    |--------------------------------------------------------------------------
    | Base table name for each Vaulter entity, resolved as
    | {table_prefix}{base}. Rename a single entity's table here without changing
    | the global prefix; PanelTablesBuilder and the models read through this map.
    */
    'table_names' => [
        // DocDrive (legacy v_document* tables) keep their historical names.
        'documents' => 'documents',
        'document_folders' => 'document_folders',
        'document_versions' => 'document_versions',
        'document_permissions' => 'document_permissions',
        'document_comments' => 'document_comments',
        'document_shares' => 'document_shares',

        // Vaulter Drive entities (M1a).
        'storages' => 'storages',
        'nodes' => 'nodes',
        'blobs' => 'blobs',
        'files' => 'files',
        'file_versions' => 'file_versions',
        'file_version_blobs' => 'file_version_blobs',
        'node_grants' => 'node_grants',
        'node_shares' => 'node_shares',

        // DocDrive (M4a) — the NODE-attached document version (§2.5). A distinct
        // table from the legacy 'document_versions' above (document_id-attached).
        'node_document_versions' => 'node_document_versions',

        'upload_sessions' => 'upload_sessions',
        'upload_parts' => 'upload_parts',
        'activity' => 'activity',

        // Reliability layer (M3b / §2.11) — v_outbox, v_webhook_endpoints,
        // v_webhook_deliveries.
        'outbox' => 'outbox',
        'webhook_endpoints' => 'webhook_endpoints',
        'webhook_deliveries' => 'webhook_deliveries',
    ],

    /*
    |--------------------------------------------------------------------------
    | Primary key type (LEGACY — kept for backward compatibility)
    |--------------------------------------------------------------------------
    | 'id'   — auto-incrementing big integers.
    | 'ulid' — 26-char ULID string keys.
    | Superseded by the 'ids' block below. When 'ids.default' is set it wins;
    | otherwise the strategy is derived from this value (id->bigint, ulid->ulid),
    | so existing installs keep working unchanged.
    */
    'id_type' => env('VAULTER_ID_TYPE', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Primary key strategy (D9)
    |--------------------------------------------------------------------------
    | The per-strategy class layer that replaces the binary id_type. Default is
    | ulid so morph keys line up with the Corex/Azgard ecosystem; uuid7 maps onto
    | native Postgres uuid columns; bigint is the legacy auto-increment path.
    | Strategy classes are resolved from these FQCNs (config-cache safe — no
    | closures), so a host may register its own strategy (e.g. Snowflake).
    */
    'ids' => [
        'default' => env('VAULTER_ID_STRATEGY', 'ulid'), // ulid|uuid7|bigint (D9)
        'strategies' => [
            'bigint' => AutoIncrementIds::class,
            'ulid' => UlidIds::class,
            'uuid7' => Uuid7Ids::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage panels (D11)
    |--------------------------------------------------------------------------
    | A storage panel is a named config-envelope bound to ANY host entity, living
    | over the ONE shared v_* schema WITHOUT its own tables by default (zero
    | migrations). List your StoragePanelProvider FQCNs here (cache-safe — FQCN
    | only, no closures); they override the default panel by id. Scaffold one with
    | `php artisan vaulter:make-panel <name>`.
    */
    'panels' => [],
    'default_panel' => DefaultStoragePanel::class,

    /*
    |--------------------------------------------------------------------------
    | Per-panel migration output (D12)
    |--------------------------------------------------------------------------
    | Where `vaulter:panel:migrations` writes the generated per-panel migration
    | for an own-tables panel. null → the host's database/migrations directory.
    */
    'migrations' => [
        'path' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    | Global feature toggles. A panel may override any of these per-panel; these
    | are the install-wide defaults read by Config::featureEnabled(). Only cheap
    | sync-path flags belong here — infrastructure features are driver bindings.
    */
    'features' => [
        'blobs' => true,
        'versions' => true,
        'grants' => true,
        'activity' => true,
        'uploads' => true,
        'share_links' => false,

        // DocDrive (M4a). 'document_versions' gates the v_node_document_versions
        // schema + the document-node Actions/HTTP (default off → opt-in).
        // 'spreadsheets' gates the feature-flagged UniverHandler (xlsx editing).
        'document_versions' => false,
        'spreadsheets' => false,

        'public_listing' => false,
        'reliability' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document handlers — the format-extensibility registry (§4.5 / §7 / H.5)
    |--------------------------------------------------------------------------
    | The DocumentHandlerRegistry resolves the best \Vaulter\Contracts\Documents\DocumentHandler
    | for a file by mime → extension → capability → priority, falling back to the
    | DownloadOnlyHandler null-object. List FQCNs here (cache-safe — FQCN only, no
    | closures); a host adds a NEW format with ONE class + an entry here (or a tagged
    | 'vaulter.handlers' binding, or Vaulter::registerHandler() at runtime) — NO
    | migration, NO core change, NOT gated by any sealed enum (H.5).
    |
    | UniverHandler (xlsx) is appended by the service provider ONLY when the
    | 'spreadsheets' feature is on, so a stock install never routes xlsx to it.
    */
    'handlers' => [
        TipTapHandler::class,
        PreviewHandler::class,
        DownloadOnlyHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    | When enabled the migrations add a tenant column. Vaulter does not scope
    | by it — supply a tenant-aware model (via 'models') that applies your scope.
    */
    'tenant' => [
        'enabled' => env('VAULTER_TENANT_ENABLED', false),
        'column' => 'tenant_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Table prefix
    |--------------------------------------------------------------------------
    | Prefix for every Vaulter table (published migrations and models read it),
    | so the package coexists with host tables and naming conventions.
    */
    'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'),

    /*
    |--------------------------------------------------------------------------
    | Authorization — legacy DocDrive resolver
    |--------------------------------------------------------------------------
    | FQCN of a \Vaulter\Contracts\Access\PermissionResolver implementation, or null
    | to register one at runtime via Vaulter::resolvePermissionsWith(...). This is
    | the LEGACY document-path resolver; the Drive ACL uses the host-role driver +
    | AccessResolver below (H.2).
    */
    'permission_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Host role drivers (D6 / H.2) — the Drive ACL
    |--------------------------------------------------------------------------
    | The pluggable, per-panel \Vaulter\Contracts\Access\HostRoleDriver lane the
    | (Vaulter-owned, NON-swappable) AccessResolver combines with v_node_grants.
    | The default 'database' driver is fully standalone — it authorises purely from
    | the grant rows with zero host dependency. Swap to 'gate'/'spatie'/'azgard'
    | (M2b) or register your own FQCN here (config-cache safe — no closures).
    |
    | Null-driver fail-loud (H.2 / D6″): a 'null' driver (open, env-gated) is
    | selectable ONLY in non-production. Production config validation rejects a
    | panel bound to it; bind a real driver before deploying.
    */
    'drivers' => [
        'permission' => env('VAULTER_PERMISSION_DRIVER', 'database'),
    ],

    'permission_drivers' => [
        'database' => DatabasePermissionResolver::class,
        'gate' => GatePermissionResolver::class,
        'spatie' => SpatiePermissionResolver::class,
        // AzGuard lane (H.2 / ADR-0007): the driver talks ONLY to the Vaulter-owned
        // AzgardGuard seam, so core has no azguard/corex dependency. A production host
        // binds a real AzgardGuard adapter (delegating to AzGuard hasScopedPermission /
        // Corex ContextGuard hasPermissionIn) AND aligns ulid morphs — see the azgard
        // sub-config below.
        'azgard' => AzgardPermissionResolver::class,
        'null' => NullRoleDriver::class, // non-prod only
    ],

    /*
    |--------------------------------------------------------------------------
    | Gate driver — ability map (H.2)
    |--------------------------------------------------------------------------
    | Used only when drivers.permission = 'gate'. The GatePermissionResolver asks
    | the Laravel Gate these abilities (node-blind — it passes the panel/workspace
    | context, never a Node). 'abilities' maps a Vaulter GrantLevel to a host gate
    | ability; the HIGHEST allowed level becomes the actor's role grant. 'super_
    | ability' short-circuits to full access; 'create_ability' gates workspace
    | creation (passed [workspaceType, workspaceId]). Plain strings — cache-safe.
    */
    'gate' => [
        'abilities' => [
            'reader' => 'vaulter.view',
            'commenter' => 'vaulter.comment',
            'writer' => 'vaulter.edit',
            'organizer' => 'vaulter.organize',
            'owner' => 'vaulter.manage',
        ],
        'super_ability' => env('VAULTER_GATE_SUPER_ABILITY', 'vaulter.super'),
        'create_ability' => env('VAULTER_GATE_CREATE_ABILITY', 'vaulter.create'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spatie driver — permission map (H.2 / D22)
    |--------------------------------------------------------------------------
    | Used only when drivers.permission = 'spatie' AND spatie/laravel-permission is
    | installed (the driver guards every API touch behind class_exists, so core has
    | no hard dependency). 'permissions' maps a GrantLevel to a spatie permission
    | name checked via hasPermissionTo(), team-scoped to the workspace; the FULL v8
    | team-switch dance lives inside the driver. 'super_permission' overrides;
    | 'create_permission' gates workspace creation. 'teams' falls back here only when
    | the host's own config('permission.teams') is unset; 'role_subject_type'/
    | 'team_subject_type' are the morph aliases role/team grant rows are addressed to.
    */
    'spatie' => [
        'permissions' => [
            'reader' => 'vaulter.view',
            'commenter' => 'vaulter.comment',
            'writer' => 'vaulter.edit',
            'organizer' => 'vaulter.organize',
            'owner' => 'vaulter.manage',
        ],
        'super_permission' => env('VAULTER_SPATIE_SUPER_PERMISSION', 'vaulter.super'),
        'create_permission' => env('VAULTER_SPATIE_CREATE_PERMISSION', 'vaulter.create'),
        'teams' => env('VAULTER_SPATIE_TEAMS', false),
        'role_subject_type' => 'role',
        'team_subject_type' => 'team',
    ],

    /*
    |--------------------------------------------------------------------------
    | AzGuard driver — Ability→permission map (H.2 / ADR-0007)
    |--------------------------------------------------------------------------
    | Used only when drivers.permission = 'azgard'. The AzgardPermissionResolver talks
    | exclusively to the Vaulter-owned AzgardGuard seam (no azguard/corex dependency in
    | core). Unlike the Gate/Spatie maps (keyed by GrantLevel) AzGuard is action-centric,
    | so 'permissions' maps a Vaulter Ability to an AzGuard permission key — the resolver
    | asks hasPermissionIn(workspaceType, workspaceId, permission) (the workspace-scoped
    | role lane of the composite order node-ACL → workspace-role → global). The SuperAdmin
    | wildcard surfaces as the absolute allow override; 'create_permission' gates workspace
    | creation. Plain strings — cache-safe.
    |
    | A production host MUST bind \Vaulter\Contracts\Access\AzgardGuard to a real adapter
    | (delegating to AzGuard hasScopedPermission / Corex ContextGuard hasPermissionIn) and
    | align ulid morphs (vaulter.ids.default=ulid + corex.ids.strategy=Ulid +
    | AZ_GUARD_MORPH_TYPE=ulid), registering workspace/owner types as Corex morph aliases.
    | No real binding ships by default — tests bind \Vaulter\Testing\FakeAzgardGuard.
    */
    'azgard' => [
        'permissions' => [
            'view' => 'documents.view',
            'download' => 'documents.download',
            'comment' => 'documents.comment',
            'edit' => 'documents.update',
            'create' => 'documents.create',
            'move' => 'documents.update',
            'copy' => 'documents.update',
            'trash' => 'documents.delete',
            'delete' => 'documents.delete',
            'restore' => 'documents.delete',
            'share' => 'documents.manage',
            'manage' => 'documents.manage',
        ],
        'create_permission' => env('VAULTER_AZGARD_CREATE_PERMISSION', 'documents.create'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage adapter for binary attachments (legacy DocDrive)
    |--------------------------------------------------------------------------
    */
    'storage_adapter' => DefaultStorageAdapter::class,

    /*
    |--------------------------------------------------------------------------
    | Content-addressed blob store (CAS, M1b / H.3)
    |--------------------------------------------------------------------------
    | The swappable CAS services. Point any of these at your own FQCN to change
    | how bytes are staged/hashed/promoted, which Flysystem disk holds the
    | canonical objects, or the high-level blob lifecycle. All three are bound in
    | the provider and resolved through the container (config-cache safe).
    */
    'cas' => [
        'content_store' => Sha256ContentStore::class,
        'storage_driver' => FlysystemStorageDriver::class,
        'blob_store' => BlobStore::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication scope (D18 / H.3)
    |--------------------------------------------------------------------------
    | The boundary the content address (sha256) is unique within:
    |  'global'  — one physical object per sha256 across the whole install.
    |  'tenant'  — unique per (tenant_id, sha256); no cross-tenant existence leak.
    |  'storage' — unique per (storage_id, sha256).
    | This drives BOTH the dedup-UNIQUE index (PanelTablesBuilder::defineBlobs)
    | and the scope-qualified storage_key "<scope>/ab/cd/<sha256>" (H.3 — a
    | scope-free path would let one scope's GC delete another scope's bytes).
    | dedup_scope ⊆ encryption scope: plaintext-sha256 dedup never crosses an
    | encryption boundary (note for the future envelope-encryption slice).
    */
    'dedup' => [
        'scope' => env('VAULTER_DEDUP_SCOPE', 'tenant'), // global|tenant|storage
    ],

    /*
    |--------------------------------------------------------------------------
    | Blob garbage collection (H.3)
    |--------------------------------------------------------------------------
    | Two-phase GC grace window. A blob whose ref-count cache reaches zero is
    | tombstoned with delete_after_at = now + grace; only after the grace passes
    | are its bytes swept. The grace must be ≥ the worst-case multipart-commit
    | latency so an in-flight upload can resurrect the blob first. Floored at 1h.
    */
    'gc' => [
        'grace_hours' => env('VAULTER_GC_GRACE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | CAS storage (H.3)
    |--------------------------------------------------------------------------
    | The default Flysystem disk for the canonical CAS objects when a blob's
    | storage (v_storages.disk) does not pin its own. The CAS bucket must be
    | UNVERSIONED: never use S3 lifecycle/versioning as a blob-deletion mechanism.
    */
    'storage' => [
        'default_disk' => env('VAULTER_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Share-link token generator
    |--------------------------------------------------------------------------
    | FQCN of a \Vaulter\Contracts\Sharing\ShareTokenGenerator. Swap to mint short,
    | hashed or prefixed tokens (must be unique and fit the token column).
    */
    'share_token_generator' => RandomShareTokenGenerator::class,

    /*
    |--------------------------------------------------------------------------
    | Bearer share links (M2c / §2.7)
    |--------------------------------------------------------------------------
    | The SEPARATE public sharing channel — a bearer token granting exactly its
    | own bitmask on its own node-set, evaluated in its own pass intersected with
    | item-safety (tenant/trash/quarantine), NEVER unioned with the identity ACL.
    |
    |  - token_generator: the \Vaulter\Contracts\Sharing\ShareTokenGenerator used to mint
    |    the raw bearer token. Only the sha256 HASH of that token is ever stored
    |    (token_hash), with a short token_prefix kept for owner-side UX lookup.
    |  - url_base: the public base a share URL is built from in NodeShareResource
    |    (the raw token is appended). The token itself is returned to the creator
    |    exactly once; the resource never re-exposes it.
    |  - prefix_length: how many leading characters of the raw token are stored as
    |    the (non-secret) token_prefix.
    */
    'share' => [
        'token_generator' => RandomShareTokenGenerator::class,
        'url_base' => env('VAULTER_SHARE_URL_BASE', '/share'),
        'prefix_length' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trash purge (D17 / §2.10)
    |--------------------------------------------------------------------------
    | The retention window before a trashed node's subtree is permanently
    | force-deleted by `php artisan vaulter:purge-trash` (schedulable). A node
    | whose trashed_at is older than now() - retention_days is purged; its blob
    | edges detach so the bytes become GC-eligible.
    */
    'trash' => [
        'retention_days' => env('VAULTER_TRASH_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resumable uploads (D16 / §2.8 / M3a)
    |--------------------------------------------------------------------------
    | Large files upload resumably (tus.io on ANY disk; S3-multipart on object
    | stores), then the assembled staged bytes go through the SAME race-free
    | BlobStore.commit (H.3 — server recomputes sha256 from the assembled staged
    | file; the client sha256 / S3 ETag is NEVER trusted as the content address).
    |
    |  - max_bytes: the hard ceiling on a session's declared total size (0 = ∞).
    |  - chunk_size: the advertised default chunk size (a hint; the server still
    |    accepts any PATCH/part size up to the remaining bytes).
    |  - session_ttl_hours: a session not committed in this window is swept by
    |    `php artisan vaulter:sweep-uploads` (staged temp + S3 multipart reclaimed,
    |    status → expired). PAIR this with an S3 bucket lifecycle rule
    |    `AbortIncompleteMultipartUpload` so an abandoned multipart never lingers as
    |    storage cost — the sweeper aborts in-flight multiparts it KNOWS about; the
    |    lifecycle rule is the backstop for any it does not.
    |  - tus.enabled: gate the tus resumable endpoints (independent of the 'uploads'
    |    schema feature flag).
    |
    | The 'uploads' feature flag (features below) gates BOTH the v_upload_sessions /
    | v_upload_parts schema AND the resumable route group.
    */
    'uploads' => [
        'max_bytes' => env('VAULTER_UPLOAD_MAX_BYTES', 5_368_709_120), // 5 GiB
        'chunk_size' => env('VAULTER_UPLOAD_CHUNK_SIZE', 8_388_608), // 8 MiB
        'session_ttl_hours' => env('VAULTER_UPLOAD_SESSION_TTL_HOURS', 24),
        'tus' => [
            'enabled' => env('VAULTER_UPLOAD_TUS_ENABLED', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reliability — transactional outbox + webhooks (D14 / §2.11 / M3b)
    |--------------------------------------------------------------------------
    | Ported from chatom 1:1. A domain event is staged in v_outbox in the same
    | request as the mutation (after-commit subscriber — see OutboxRecorder), then
    | the relay (vaulter:relay-outbox) fans each pending row out into one signed
    | v_webhook_deliveries row per subscribed active endpoint and queues the HTTP
    | POST. At-least-once with exponential backoff + DLQ; consumers DEDUPE on the
    | event_id carried in the payload (the outbox is idempotent by event_id, the
    | delivery may be re-queued).
    |
    |  - timeout: HTTP request timeout (seconds) for a single delivery POST.
    |  - max_attempts: total attempts before a delivery is parked dead (DLQ).
    |  - backoff: per-attempt retry delay (seconds); the tail entry caps further
    |    retries.
    |  - disable_after_failures: consecutive dead deliveries that flip an endpoint's
    |    is_active off (circuit breaker against a permanently broken receiver).
    |  - signature_header / event_header: the HMAC-SHA256 signature + event-name
    |    headers the delivery POST carries; the signature is over the exact JSON
    |    body and a timestamp (timestamp_header) to make it replay-resistant.
    |  - retention_days: the age past which dispatched outbox rows and terminal
    |    deliveries become eligible for pruning.
    |
    | The 'reliability' feature flag (features above) gates BOTH the v_outbox /
    | v_webhook_* schema AND the outbox subscriber + endpoint routes.
    */
    'webhooks' => [
        'timeout' => 5,
        'max_attempts' => 5,
        'backoff' => [10, 30, 60, 300, 1800],
        'disable_after_failures' => 10,
        'signature_header' => 'X-Vaulter-Signature',
        'timestamp_header' => 'X-Vaulter-Timestamp',
        'event_header' => 'X-Vaulter-Event',
        'retention_days' => 30,

        // The Gate ability that gates the webhook-endpoint management surface
        // (register/update/deactivate/delete). The host defines this ability; until
        // it does, an authenticated user is allowed (the route still requires auth) —
        // a host MUST tighten this in production. Set to null to allow any
        // authenticated user explicitly.
        'manage_ability' => env('VAULTER_WEBHOOKS_MANAGE_ABILITY', 'vaulter.webhooks.manage'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API routes
    |--------------------------------------------------------------------------
    */
    'route_prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Versioning (legacy DocDrive)
    |--------------------------------------------------------------------------
    | Max stored versions per document (0 = unlimited); oldest are pruned.
    */
    'max_versions' => 50,

    /*
    |--------------------------------------------------------------------------
    | Document node versions (M4a / §2.5)
    |--------------------------------------------------------------------------
    | The NODE-attached document version history (v_node_document_versions).
    |  - max: stored versions per node before pruning (0 = unlimited); the oldest
    |    NON-keep_forever versions are pruned past this. Falls back to max_versions.
    |  - autosave_debounce_seconds: an autosave within this window of the head
    |    autosave version UPDATES it in place (no new row), so a typing session does
    |    not flood the history; a named version always creates a fresh row.
    */
    'versions' => [
        'max' => 50,
        'autosave_debounce_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */
    'enabled_document_types' => ['richtext', 'table', 'markdown', 'template'],
    'max_document_size_kb' => 5120,

    /*
    |--------------------------------------------------------------------------
    | Drive uploads (binary files)
    |--------------------------------------------------------------------------
    | The upload policy for the BINARY Drive path (v_files / v_blobs), kept apart
    | from the TipTap document limit above so a file disk and a document editor
    | can be sized independently.
    |
    |  - max_upload_kb: hard size ceiling (kilobytes) for an uploaded file,
    |    enforced by UploadFileRequest. Defaults to max_document_size_kb so the
    |    behaviour is unchanged until a host pins its own value.
    |  - allowed_mime: an OPTIONAL mime allowlist. EMPTY = permissive (any mime
    |    accepted, the default). Listing one or more mimes (e.g. 'application/pdf',
    |    'image/*') restricts uploads to that set via Laravel's `mimetypes` rule.
    */
    'drive' => [
        'max_upload_kb' => null,
        'allowed_mime' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Export formats
    |--------------------------------------------------------------------------
    | Map of format => \Vaulter\Contracts\Documents\DocumentExporter. Add your own (PDF,
    | DOCX, …) here, or at runtime via Vaulter::registerExporter().
    */
    'exporters' => [
        'html' => HtmlExporter::class,
        'markdown' => MarkdownExporter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time collaboration (M4 / §7 / ADR-0001)
    |--------------------------------------------------------------------------
    | Yjs is the SOURCE OF TRUTH (the binary CRDT state stored on a document
    | node's version); TipTap JSON is the derived projection. The transport is
    | swappable behind the driver:
    |
    |   'reverb'     — Yjs over Laravel Reverb (no extra service; default). The
    |                  Vue client joins the PRIVATE broadcast channel for the room
    |                  (channel_prefix.nodeId); Laravel's channel-auth endpoint
    |                  gates it through the AccessResolver. No room JWT is minted.
    |   'hocuspocus' — an external Node Hocuspocus hub (see hocuspocus-server/).
    |                  The client connects to the hub URL with a signed room JWT;
    |                  the hub's onAuthenticate/onStoreDocument call BACK into the
    |                  collab/authenticate + collab/store webhooks below.
    |
    | channel_prefix is the room namespace; a room is ALWAYS prefix.nodeId so a
    | token is bound to exactly one node (no cross-node access).
    |
    | webhook_secret is the shared secret the Node hub presents on the server-to-
    | server webhook endpoints. When null/empty the webhook surface accepts ONLY a
    | valid signed JWT (it is NEVER open). The driver-level hocuspocus.secret is
    | both the room-JWT signing key AND the key the webhooks verify a JWT against.
    */
    'collaboration' => [
        'driver' => env('VAULTER_COLLAB_DRIVER', 'reverb'),
        'channel_prefix' => 'vaulter.documents',
        'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),

        'drivers' => [
            'reverb' => [
                'class' => ReverbCollaborationDriver::class,
                'connection' => env('VAULTER_COLLAB_CONNECTION', 'reverb'),
            ],
            'hocuspocus' => [
                'class' => HocuspocusCollaborationDriver::class,
                'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
                'app_id' => env('VAULTER_COLLAB_APP_ID'),
                'secret' => env('VAULTER_COLLAB_SECRET'),
                'token_ttl' => 3600,
            ],
        ],
    ],
];
