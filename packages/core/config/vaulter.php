<?php

declare(strict_types=1);

use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Drivers\Permission\GatePermissionResolver;
use Vaulter\Drivers\Permission\SpatiePermissionResolver;
use Vaulter\Engines\Handlers\DownloadOnlyHandler;
use Vaulter\Engines\Handlers\PreviewHandler;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\FileVersionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Panels\DefaultStoragePanel;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;
use Vaulter\Support\RandomShareTokenGenerator;
use Vaulter\Testing\NullRoleDriver;
use Vaulter\Webhooks\DefaultOutboundUrlGuard;

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
        // Vaulter Drive entities (M1a).
        'storage' => Storage::class,
        'node' => Node::class,
        'file' => File::class,
        'file_version' => FileVersion::class,
        'blob' => Blob::class,
        'file_version_blob' => FileVersionBlob::class,
        'grant' => NodeGrant::class,
        'node_share' => NodeShare::class,

        // Resumable-upload models (upload_session / upload_part) are OWNED by
        // axioma-studio/vaulter-drive (P2 #5 / ADR-0015, T9): core ships no default
        // here — the drive provider merges them via config/vaulter-drive.php, and
        // Support\Config::uploadSessionModel()/uploadPartModel() guard-throw when the
        // package is absent. The v_upload_sessions/v_upload_parts SCHEMA stays in core.

        // The NODE-attached comment (v_node_comments, ADR-0014) and document-version
        // (v_node_document_versions, §2.5) models are OWNED by axioma-studio/
        // vaulter-documents (P2 #5 / ADR-0015, T9): no default here — the documents
        // provider merges them via config/vaulter-documents.php, and Support\Config::
        // nodeCommentModel()/nodeDocumentVersionModel() guard-throw when absent. Their
        // SCHEMA (v_node_comments/v_node_document_versions) stays in core.
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
        // Vaulter Drive entities (M1a).
        'storages' => 'storages',
        'nodes' => 'nodes',
        'blobs' => 'blobs',
        'files' => 'files',
        'file_versions' => 'file_versions',
        'file_version_blobs' => 'file_version_blobs',
        'node_grants' => 'node_grants',
        'node_shares' => 'node_shares',
        'node_comments' => 'node_comments',

        // Documents (M4a) — the NODE-attached document version history (§2.5).
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
        // Keys owned by the host are independent of Vaulter entity IDs.
        'host_keys' => env('VAULTER_HOST_KEYS', 'string'), // string|bigint|uuid|ulid
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

        // ADR-0014 шаг 1 — gates the v_node_comments schema + the node-comment
        // Actions/HTTP (default off → opt-in, additive over the legacy DocVault).
        'comments' => false,

        // Documents (M4a). 'document_versions' gates the v_node_document_versions
        // schema + the document-node Actions/HTTP (default off → opt-in).
        // 'spreadsheets' gates the feature-flagged UniverHandler (xlsx editing).
        'document_versions' => false,
        'spreadsheets' => false,

        // Realtime collaboration (VLT-REMED P3.1 / F-P1.6-01, D13/D20). A SEPARATE
        // opt-in from 'document_versions': 'document_versions' alone gates the
        // NON-realtime editing surface (content GET/PATCH, named versions/restore) —
        // "TipTap without realtime" is meant to be reachable on its own. 'collaboration'
        // additionally gates the Node/Yjs realtime surface (collab/token,
        // collab/authenticate, collab/store, the broadcast channel, the collab
        // driver registration): both flags must be on for the realtime routes to
        // mount (document_versions && collaboration). Default false — a host that
        // already relies on realtime collab MUST flip this to true after upgrading
        // (breaking change, see UPGRADE.md).
        'collaboration' => false,

        'public_listing' => false,
        'reliability' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Document handlers — the format-extensibility registry (§4.5 / §7 / H.5)
    |--------------------------------------------------------------------------
    | The DocumentHandlerRegistry resolves the best \Vaulter\Contracts\Handlers\FileHandler
    | for a file by mime → extension → capability → priority, falling back to the
    | DownloadOnlyHandler null-object. List FQCNs here (cache-safe — FQCN only, no
    | closures); a host adds a NEW format with ONE class + an entry here (or a tagged
    | 'vaulter.handlers' binding, or Vaulter::registerHandler() at runtime) — NO
    | migration, NO core change, NOT gated by any sealed enum (H.5).
    |
    | Preview/DownloadOnly are the core-owned BASELINE (VLT-CANON P0.4/D10, superseding
    | H.5/T5): a drive-only host with no other package installed still resolves a real
    | preview/download descriptor for every leaf node. axioma-studio/vaulter-documents
    | (P2 #5 / ADR-0015, T9) ADDS TipTapHandler (+ UniverHandler when the 'spreadsheets'
    | feature is on) via its own config/vaulter-documents.php merge — it never owns the
    | baseline.
    */
    'handlers' => [
        PreviewHandler::class,
        DownloadOnlyHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | TipTap content schema — the store-time allow-list seam (D27 / D28)
    |--------------------------------------------------------------------------
    | Vaulter validates rich-text content against a CLOSED allow-list of node types,
    | mark types and per-type attributes before it is stored (F-P1.11-01/-02): an
    | unknown node, an unlisted attribute or a non-http(s)/mailto URL rejects the whole
    | document. The canon shipped in ValueObjects\Documents\TipTapContent covers exactly
    | what this package offers the editor through #[TiptapExtensions] (StarterKit, Image,
    | Table, Link, CodeBlock).
    |
    | If you mount your OWN Tiptap extensions in the editor, declare their nodes/marks/
    | attributes here — otherwise the content they produce cannot be saved.
    |
    | The seam is ADDITIVE (D28): these keys only ADD to the canon, they never replace
    | it — dropping 'doc'/'text' from the list would silently break every save, and
    | "overriding" the attribute allow-list is a classic way to hand yourself an XSS.
    |
    | Widening the allow-list does NOT switch the XSS barrier off (R-11): EVERY URL-bearing
    | attribute is scheme-checked (http/https/mailto) at save AND when the content is read
    | back into the editor — the ones you add here included, not just 'link.href' and
    | 'image.src'. An attribute is URL-bearing when its name says so ('src', 'href', 'url',
    | 'poster', 'action', 'cite', …) or when you declare it in 'url_attributes' (for a URL
    | living under a name we cannot recognise, e.g. 'location').
    |
    | And whatever the name: an attribute VALUE carrying an executable scheme ('javascript:',
    | 'data:', 'vbscript:') is REJECTED at save and stripped on read (P5.2). Declaring an
    | attribute in 'url_attributes' buys you the full allow-list check (a relative or
    | 'file:' URL is rejected too) — it is not what stands between your editor and an XSS.
    |
    | Example:
    |   'extra_nodes' => ['mention', 'taskList', 'taskItem'],
    |   'extra_marks' => ['highlight'],
    |   'extra_attributes' => ['mention' => ['id', 'label'], 'taskItem' => ['checked']],
    |   'url_attributes' => ['embed' => ['location']],
    */
    'tiptap' => [
        'extra_nodes' => [],
        'extra_marks' => [],
        'extra_attributes' => [],
        'url_attributes' => [],
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
    | Database connection (F-P2.3-02)
    |--------------------------------------------------------------------------
    | The named Laravel database connection every Vaulter-owned table and model
    | reads/writes through. null (default) means "the host's default connection"
    | (Support\Config::connection() resolves it via Schema::connection(null) /
    | Model::getConnectionName() falling back to the connection resolver's own
    | default) — a host that does nothing here sees no change. Set this to embed
    | the Vaulter schema on its own database/instance (sharding, data placement,
    | isolating a heavy CAS/activity workload) without forking the package.
    */
    'connection' => env('VAULTER_DB_CONNECTION', null),

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
    | Content-addressed blob store (CAS, M1b / H.3)
    |--------------------------------------------------------------------------
    | The swappable CAS engine (content_store / storage_driver / blob_store) is
    | OWNED by the axioma-studio/vaulter-media package (P2 #5, D2/D3): its provider
    | merges the concrete-class defaults under this same `vaulter.cas.*` key, so a
    | host keeps reading/overriding config('vaulter.cas.content_store') etc. with
    | no path change. The dedup/gc/storage knobs below stay in core because the
    | v_blobs SCHEMA (core-owned) reads dedup.scope regardless of that package.
    */

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
    |
    | orphan_grace_hours is the separate, generally MORE generous window the
    | `vaulter:reconcile-blobs --orphans` disk-walk applies before it treats an
    | object that has NO blob row as a deletable orphan. A commit promotes its bytes
    | BEFORE its row is durable, and a resumable upload may promote then commit much
    | later, so a fresh row-less object is likely a commit-in-progress, NOT garbage.
    | Defaults to the GC grace; floored at 1h.
    */
    'gc' => [
        'grace_hours' => env('VAULTER_GC_GRACE_HOURS', 24),
        'orphan_grace_hours' => env('VAULTER_ORPHAN_GRACE_HOURS', env('VAULTER_GC_GRACE_HOURS', 24)),
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
    |  - default_ttl_hours (P1.2 / F-P1.3-03, S4.1): the expiry a link gets when the
    |    creator supplies none. A share is never minted with a null expires_at
    |    (bearer links must not be immortal by omission); default 72h.
    |  - max_ttl_hours (P1.2 / F-P1.3-03): the ceiling `expires_at` may not exceed,
    |    enforced by StoreShareLinkRequest validation on an EXPLICIT expiry too —
    |    the default only covers the omitted case. Default 720h (30 days).
    */
    'share' => [
        'token_generator' => RandomShareTokenGenerator::class,
        'url_base' => env('VAULTER_SHARE_URL_BASE', '/share'),
        'prefix_length' => 8,
        'default_ttl_hours' => env('VAULTER_SHARE_DEFAULT_TTL_HOURS', 72),
        'max_ttl_hours' => env('VAULTER_SHARE_MAX_TTL_HOURS', 720),
    ],

    /*
    |--------------------------------------------------------------------------
    | Download headers (P1.1 / F-P1.3-04, F-P1.11-03)
    |--------------------------------------------------------------------------
    | X-Content-Type-Options: nosniff is always set on a download response.
    | `Content-Type` mirrors the file's declared mime_type ONLY when it is on this
    | closed allow-list; anything else — including active-content types such as
    | text/html or image/svg+xml — is served as application/octet-stream instead,
    | so a stored file can never render inline as HTML/JS on the app's own origin.
    */
    'downloads' => [
        'inline_mime_whitelist' => [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
        ],
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
    |  - chunk_size: the advertised default chunk size (a HINT; the server accepts any
    |    PATCH/part size up to max_chunk_bytes — the separate hard cap below).
    |  - max_chunk_bytes: the hard ceiling on a SINGLE PATCH/part body — a memory/IO
    |    DoS lever kept distinct from the chunk_size hint. It SHIPS ENABLED (64 MiB =
    |    8x the advertised hint): a request whose Content-Length exceeds it is refused
    |    413 before its body is read, and a body with no declared length is cut off at
    |    the ceiling before a byte is staged. 0 disables the cap — an unbounded single
    |    PATCH is then a memory/IO DoS vector, so only lift it deliberately.
    |  - session_ttl_hours: a session not committed in this window is swept by
    |    `php artisan vaulter:sweep-uploads` (staged temp + S3 multipart reclaimed,
    |    status → expired). PAIR this with an S3 bucket lifecycle rule
    |    `AbortIncompleteMultipartUpload` so an abandoned multipart never lingers as
    |    storage cost — the sweeper aborts in-flight multiparts it KNOWS about; the
    |    lifecycle rule is the backstop for any it does not.
    |  - tus.enabled: gate the tus resumable endpoints (independent of the 'uploads'
    |    schema feature flag).
    |  - max_active_sessions (P1.3 / F-P1.4-09): the cap on how many OPEN
    |    (initiated|uploading) sessions a single actor may hold at once — `initiate()`
    |    refuses a new session past this cap BEFORE staging a byte. 0 disables the
    |    cap (unbounded).
    |
    | The 'uploads' feature flag (features below) gates BOTH the v_upload_sessions /
    | v_upload_parts schema AND the resumable route group.
    */
    'uploads' => [
        'max_bytes' => env('VAULTER_UPLOAD_MAX_BYTES', 5_368_709_120), // 5 GiB
        'chunk_size' => env('VAULTER_UPLOAD_CHUNK_SIZE', 8_388_608), // 8 MiB
        'max_chunk_bytes' => env('VAULTER_UPLOAD_MAX_CHUNK_BYTES', 67_108_864), // 64 MiB (0 = no per-chunk cap)
        'session_ttl_hours' => env('VAULTER_UPLOAD_SESSION_TTL_HOURS', 24),
        'max_active_sessions' => env('VAULTER_UPLOAD_MAX_ACTIVE_SESSIONS', 20),
        'tus' => [
            'enabled' => env('VAULTER_UPLOAD_TUS_ENABLED', true),
        ],

        // F-P1.4-08: the closed allow-list of tus Upload-Metadata / JSON `metadata`
        // keys the server stores; anything outside it is silently dropped (not
        // rejected — one unknown vendor key must not fail the whole upload), and any
        // accepted value is truncated to max_value_length. Unbounded keys/lengths
        // otherwise grow v_upload_sessions.metadata without limit (U4.3/U4.4).
        'metadata' => [
            'allowed_keys' => ['filename', 'filetype', 'name', 'mime_type'],
            'max_value_length' => 512,
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

        // The visibility-timeout (seconds) a due retry's next_retry_at is bumped
        // forward when a sweep claims it (F-P1.7-05): a concurrent/next sweep won't
        // re-dispatch the same delivery, and a lost job becomes due again after it.
        'retry_claim_seconds' => 60,

        // The Gate ability that gates the webhook-endpoint management surface
        // (register/update/deactivate/delete). The host defines this ability; until
        // it does, an authenticated user is allowed (the route still requires auth) —
        // a host MUST tighten this in production. Set to null to allow any
        // authenticated user explicitly.
        'manage_ability' => env('VAULTER_WEBHOOKS_MANAGE_ABILITY', 'vaulter.webhooks.manage'),

        // Outbound delivery SSRF hardening (D5 / N2 / F-P1.7-01). The endpoint URL is
        // a user-controlled primitive, so the relay resolves it (A + AAAA), refuses any
        // address in a private/loopback/link-local/reserved range, PINS the vetted IP
        // onto the connection (no DNS-rebind window), disables redirects and locks the
        // transport to http(s). A host may swap the guard or re-open a trusted internal
        // range — the default is closed.
        'delivery' => [
            // Swap for a host implementation of Vaulter\Contracts\Webhooks\OutboundUrlGuard.
            'url_guard' => DefaultOutboundUrlGuard::class,

            // Schemes an outbound delivery may dial. Anything else is refused.
            'allowed_schemes' => ['http', 'https'],

            // Ranges a delivery must NOT resolve into. null → the closed default set
            // (loopback, RFC-1918, link-local incl. 169.254.169.254, CGNAT, IPv6
            // ULA/link-local). Set an explicit list to override wholesale.
            'blocked_ip_ranges' => null,

            // Ranges a host EXPLICITLY re-opens for a trusted internal receiver. Empty
            // by default — the guard stays closed until a host opts in.
            'allowed_ip_ranges' => [],

            // NB: there is deliberately no 'follow_redirects' key (VLT-REMED D30 / R-05).
            // Redirects are NEVER followed: the IP pin above covers the original host only,
            // so a receiver answering `302 Location: http://169.254.169.254/…` would send the
            // delivery to the metadata service through a fresh, unguarded DNS resolve. A
            // config flag that turns a security barrier off is a mine, not flexibility.

            // TCP connect timeout (seconds) — a fast fail on an unreachable host.
            'connect_timeout' => 2,

            // Hard ceiling (bytes) on the receiver's response body; delivery needs only
            // the status. 0 disables the check.
            'max_response_bytes' => 65536,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API routes
    |--------------------------------------------------------------------------
    */
    'route_prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
    // Keep actor authentication separate from bearer shares and signed hub calls.
    // Request metrics are appended automatically to each stack.
    'routes' => [
        'middleware' => [
            'authenticated' => ['api', 'auth:sanctum'],
            'public' => ['api'],
            'hub' => ['api'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (P1.3 / F-P1.8-01)
    |--------------------------------------------------------------------------
    | Two package-owned NAMED limiters, registered idempotently in
    | VaulterServiceProvider (a host that already defined a limiter under the same
    | name wins — the package never clobbers it):
    |
    |  - vaulter-public: the token-resolved public share surface (`share/{token}*`)
    |    and the server-to-server collaboration webhooks (`collab/authenticate`,
    |    `collab/store`); keyed by requester IP.
    |  - vaulter-uploads: the resumable-upload surface (tus + S3-multipart); keyed
    |    by actor id, falling back to IP when unauthenticated.
    */
    'rate_limits' => [
        'public' => [
            'per_minute' => env('VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE', 60),
        ],
        'uploads' => [
            'per_minute' => env('VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE', 120),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Versioning (legacy Documents)
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
    |  - name_rules: the shared node-name validation policy ({@see
    |    \Vaulter\Support\DriveNameRules}), enforced identically by create/rename/
    |    upload/copy requests (§2.2). max_length is the Laravel `max:` bound;
    |    no_separators_pattern is the raw (no `regex:` prefix) negated-match pattern
    |    that rejects a name carrying a path separator — the tree is adjacency-based,
    |    a name is a single segment, never a path. Defaults reproduce the pre-P4.5
    |    hardcoded values (255 / `/^[^\/\\]+$/`) — out-of-the-box behaviour does not
    |    change until a host overrides one of these keys (VLT-REMED P4.5, F-P3.1-01).
    */
    'drive' => [
        'max_upload_kb' => null,
        'allowed_mime' => [],
        'name_rules' => [
            'max_length' => 255,
            'no_separators_pattern' => '/^[^\/\\\\]+$/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Export formats
    |--------------------------------------------------------------------------
    | Map of format => \Vaulter\Contracts\Handlers\DocumentExporter. Add your own (PDF,
    | DOCX, …) here, or at runtime via Vaulter::registerExporter().
    |
    | The built-in html/markdown exporters are OWNED by axioma-studio/vaulter-documents
    | (P2 #5 / ADR-0015, T9): core ships no default here — the documents provider
    | merges them via config/vaulter-documents.php. Support\Config::exporters() falls
    | back to [] (no documents installed = no exporters registered).
    */
    'exporters' => [],

    /*
    |--------------------------------------------------------------------------
    | Real-time collaboration (M4 / §7 / ADR-0001)
    |--------------------------------------------------------------------------
    | Yjs is the SOURCE OF TRUTH (the binary CRDT state stored on a document
    | node's version); TipTap JSON is the derived projection. The transport is
    | swappable behind the driver:
    |
    |   'hocuspocus' — the RECOMMENDED collaborative-editing transport (default).
    |                  An external Node Hocuspocus hub (see hocuspocus-server/) —
    |                  the Yjs collaboration backend TipTap documents and maintains.
    |                  The client connects to the hub URL with a signed room JWT;
    |                  the hub's onAuthenticate/onStoreDocument call BACK into the
    |                  collab/authenticate + collab/store webhooks below. This is the
    |                  only path with awareness sync + server-debounced persistence
    |                  and a real read-only guarantee on the socket.
    |   'reverb'     — DEPRECATED for Yjs document editing. Reverb is Laravel's
    |                  general broadcast bus (Pusher protocol) — great for app
    |                  notifications/presence/activity, but it relays peer client-
    |                  events without server-side content gating, so it cannot
    |                  enforce read-only on the socket nor debounce persistence the
    |                  way a Yjs backend does. Pushing Yjs through it is a custom
    |                  protocol, not a standard TipTap integration. Kept only for
    |                  backward compatibility; use 'hocuspocus' for collaboration.
    |
    | channel_prefix is the room namespace; a room is ALWAYS prefix.nodeId so a
    | token is bound to exactly one node (no cross-node access).
    |
    | webhook_secret is the shared secret the Node hub presents on the server-to-
    | server webhook endpoints. The driver-level hocuspocus.secret is both the
    | room-JWT signing key AND the key the webhooks verify a JWT against — a
    | SEPARATE key from webhook_secret by design (C1.2: never reuse a JWT-signing
    | key as a body-integrity key).
    |
    | HARDENING (VLT-REMED P3.2 / F-P1.6-02, N2): every collab/authenticate + collab/
    | store call — whether it identified itself with the secret header OR a JWT —
    | must ALSO carry a valid HMAC-SHA256 signature of "{timestamp}.{rawBody}" keyed
    | by webhook_secret (X-Vaulter-Collab-Timestamp + X-Vaulter-Collab-Signature),
    | the same canonical string {@see \Vaulter\Webhooks\WebhookSigner} already uses
    | for OUTBOUND deliveries. This is an ADDITIONAL barrier on top of the identity
    | check, never a replacement for it — an unsigned call, a stale timestamp
    | (outside webhook_timestamp_tolerance) or a replayed signature is refused even
    | with a perfectly valid secret/JWT. BREAKING: webhook_secret is now REQUIRED for
    | ANY collab webhook call to succeed (JWT-only, no webhook_secret configured, no
    | longer works) — see UPGRADE.md.
    */
    'collaboration' => [
        'driver' => env('VAULTER_COLLAB_DRIVER', 'hocuspocus'),
        'channel_prefix' => 'vaulter.documents',
        'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),

        // The ± tolerance (seconds) an incoming webhook's X-Vaulter-Collab-Timestamp
        // may drift from now before the call is refused as stale/replayed; also the
        // TTL a seen signature (nonce) is remembered for replay detection.
        'webhook_timestamp_tolerance' => 300,

        'drivers' => [
            'reverb' => [
                'connection' => env('VAULTER_COLLAB_CONNECTION', 'reverb'),
            ],
            'hocuspocus' => [
                'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
                'app_id' => env('VAULTER_COLLAB_APP_ID'),
                'secret' => env('VAULTER_COLLAB_SECRET'),
                'token_ttl' => 3600,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability (VLT-LOAD P8 / D35)
    |--------------------------------------------------------------------------
    | MetricsRecorder seam: disabled by default (Null). When enabled, `recorder`
    | must be an FQCN implementing Vaulter\Contracts\Observability\MetricsRecorder
    | (typically a host/load adapter). Invalid or missing class falls back to Null.
    */
    'observability' => [
        'enabled' => env('VAULTER_OBSERVABILITY_ENABLED', false),
        'recorder' => env('VAULTER_OBSERVABILITY_RECORDER'),
        'tracing' => [
            'enabled' => env('VAULTER_TRACING_ENABLED', false),
            'recorder' => env('VAULTER_TRACING_RECORDER'),
        ],
    ],
];
