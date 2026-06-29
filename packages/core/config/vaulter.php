<?php

declare(strict_types=1);

use Vaulter\Collaboration\HocuspocusCollaborationDriver;
use Vaulter\Collaboration\ReverbCollaborationDriver;
use Vaulter\Export\HtmlExporter;
use Vaulter\Export\MarkdownExporter;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentComment;
use Vaulter\Models\DocumentFolder;
use Vaulter\Models\DocumentPermission;
use Vaulter\Models\DocumentShare;
use Vaulter\Models\DocumentVersion;
use Vaulter\Panels\DefaultStoragePanel;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;
use Vaulter\Support\DefaultStorageAdapter;
use Vaulter\Support\RandomShareTokenGenerator;

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
    | Authorization
    |--------------------------------------------------------------------------
    | FQCN of a \Vaulter\Contracts\PermissionResolver implementation, or null
    | to register one at runtime via Vaulter::resolvePermissionsWith(...).
    */
    'permission_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Storage adapter for binary attachments
    |--------------------------------------------------------------------------
    */
    'storage_adapter' => DefaultStorageAdapter::class,

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
