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
    | Primary key type
    |--------------------------------------------------------------------------
    | 'id'   — auto-incrementing big integers (default).
    | 'ulid' — 26-char ULID string keys (recommended for multi-tenant SaaS).
    | Consumed by the published migrations and the HasVaulterKeys concern.
    */
    'id_type' => env('VAULTER_ID_TYPE', 'id'),

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
