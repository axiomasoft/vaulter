<?php

declare(strict_types=1);

use DocVault\Collaboration\HocuspocusCollaborationDriver;
use DocVault\Collaboration\ReverbCollaborationDriver;
use DocVault\Models\Document;
use DocVault\Models\DocumentComment;
use DocVault\Models\DocumentFolder;
use DocVault\Models\DocumentPermission;
use DocVault\Models\DocumentVersion;
use DocVault\Support\DefaultStorageAdapter;

/**
 * DocVault configuration.
 *
 * Publish: php artisan vendor:publish --tag=docvault-config
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Eloquent models (swappable)
    |--------------------------------------------------------------------------
    | Point these at your own subclasses to add behaviour (ULID keys, tenant
    | scoping, audit). DocVault always resolves models through this map.
    */
    'models' => [
        'document' => Document::class,
        'version' => DocumentVersion::class,
        'folder' => DocumentFolder::class,
        'permission' => DocumentPermission::class,
        'comment' => DocumentComment::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Primary key type
    |--------------------------------------------------------------------------
    | 'id'   — auto-incrementing big integers (default).
    | 'ulid' — 26-char ULID string keys (recommended for multi-tenant SaaS).
    | Consumed by the published migrations and the HasDocVaultKeys concern.
    */
    'id_type' => env('DOCVAULT_ID_TYPE', 'id'),

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    | When enabled the migrations add a tenant column. DocVault does not scope
    | by it — supply a tenant-aware model (via 'models') that applies your scope.
    */
    'tenant' => [
        'enabled' => env('DOCVAULT_TENANT_ENABLED', false),
        'column' => 'tenant_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    | FQCN of a \DocVault\Contracts\PermissionResolver implementation, or null
    | to register one at runtime via DocVault::resolvePermissionsWith(...).
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
    | API routes
    |--------------------------------------------------------------------------
    */
    'route_prefix' => env('DOCVAULT_ROUTE_PREFIX', 'api/docvault'),
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
    | Real-time collaboration
    |--------------------------------------------------------------------------
    | 'reverb'     — Yjs over Laravel Reverb (no extra service; default).
    | 'hocuspocus' — external Node Hocuspocus hub (see hocuspocus-server/).
    */
    'collaboration' => [
        'driver' => env('DOCVAULT_COLLAB_DRIVER', 'reverb'),
        'channel_prefix' => 'docvault.documents',

        'drivers' => [
            'reverb' => [
                'class' => ReverbCollaborationDriver::class,
                'connection' => env('DOCVAULT_COLLAB_CONNECTION', 'reverb'),
            ],
            'hocuspocus' => [
                'class' => HocuspocusCollaborationDriver::class,
                'url' => env('DOCVAULT_COLLAB_URL', 'ws://localhost:1234'),
                'app_id' => env('DOCVAULT_COLLAB_APP_ID'),
                'secret' => env('DOCVAULT_COLLAB_SECRET'),
                'token_ttl' => 3600,
            ],
        ],
    ],
];
