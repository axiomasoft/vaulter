<?php

declare(strict_types=1);

use Vaulter\Cas\BlobStore;
use Vaulter\Cas\FlysystemStorageDriver;
use Vaulter\Cas\Sha256ContentStore;
use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Collaboration\ReverbCollaborationDriver;
use Vaulter\Drivers\Permission\DatabasePermissionResolver;
use Vaulter\Drivers\Permission\GatePermissionResolver;
use Vaulter\Drivers\Permission\SpatiePermissionResolver;
use Vaulter\Export\HtmlExporter;
use Vaulter\Export\MarkdownExporter;
use Vaulter\Models\Blob;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentComment;
use Vaulter\Models\DocumentFolder;
use Vaulter\Models\DocumentPermission;
use Vaulter\Models\DocumentShare;
use Vaulter\Models\DocumentVersion;
use Vaulter\Models\File;
use Vaulter\Models\FileVersion;
use Vaulter\Models\FileVersionBlob;
use Vaulter\Models\Node;
use Vaulter\Models\NodeGrant;
use Vaulter\Models\NodeShare;
use Vaulter\Models\Storage;
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
        'document_versions' => false,
        'public_listing' => false,
        'reliability' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline handlers (stub)
    |--------------------------------------------------------------------------
    | Reserved for the format/pipe registry (ingest_file/create_node/move_node
    | pipe lists). Empty until the pipeline lands in a later milestone.
    */
    'handlers' => [],

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
    | FQCN of a \Vaulter\Contracts\PermissionResolver implementation, or null
    | to register one at runtime via Vaulter::resolvePermissionsWith(...). This is
    | the LEGACY document-path resolver; the Drive ACL uses the host-role driver +
    | AccessResolver below (H.2).
    */
    'permission_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Host role drivers (D6 / H.2) — the Drive ACL
    |--------------------------------------------------------------------------
    | The pluggable, per-panel \Vaulter\Contracts\HostRoleDriver lane the
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
        // 'azgard' => …  (deferred — needs Corex/Azgard morph alignment, see H.2)
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
    | FQCN of a \Vaulter\Contracts\ShareTokenGenerator. Swap to mint short,
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
    |  - token_generator: the \Vaulter\Contracts\ShareTokenGenerator used to mint
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
    | API routes
    |--------------------------------------------------------------------------
    */
    'route_prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Versioning
    |--------------------------------------------------------------------------
    | Max stored versions per document (0 = unlimited); oldest are pruned.
    */
    'max_versions' => 50,

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */
    'enabled_document_types' => ['richtext', 'table', 'markdown', 'template'],
    'max_document_size_kb' => 5120,

    /*
    |--------------------------------------------------------------------------
    | Export formats
    |--------------------------------------------------------------------------
    | Map of format => \Vaulter\Contracts\DocumentExporter. Add your own (PDF,
    | DOCX, …) here, or at runtime via Vaulter::registerExporter().
    */
    'exporters' => [
        'html' => HtmlExporter::class,
        'markdown' => MarkdownExporter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time collaboration
    |--------------------------------------------------------------------------
    | 'reverb'     — Yjs over Laravel Reverb (no extra service; default).
    | 'hocuspocus' — external Node Hocuspocus hub (see hocuspocus-server/).
    */
    'collaboration' => [
        'driver' => env('VAULTER_COLLAB_DRIVER', 'reverb'),
        'channel_prefix' => 'vaulter.documents',

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
