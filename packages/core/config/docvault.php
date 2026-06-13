<?php

declare(strict_types=1);

use DocVault\Support\DefaultStorageAdapter;

/**
 * DocVault configuration.
 *
 * Publish: php artisan vendor:publish --tag=docvault-config
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Permission resolver
    |--------------------------------------------------------------------------
    | FQCN of the host-app class implementing \DocVault\Contracts\PermissionResolver.
    | Leave null to register it manually via DocVault::resolvePermissionsWith(...).
    */
    'permission_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Storage adapter
    |--------------------------------------------------------------------------
    | Pluggable storage for binary attachments (\DocVault\Contracts\StorageAdapter).
    */
    'storage_adapter' => DefaultStorageAdapter::class,

    /*
    |--------------------------------------------------------------------------
    | API routes
    |--------------------------------------------------------------------------
    */
    'route_prefix' => 'api/docvault',
    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Versioning
    |--------------------------------------------------------------------------
    | Maximum stored versions per document (0 = unlimited).
    */
    'max_versions' => 50,

    /*
    |--------------------------------------------------------------------------
    | Document types
    |--------------------------------------------------------------------------
    */
    'document_types' => ['richtext', 'table', 'markdown', 'template'],

    /*
    |--------------------------------------------------------------------------
    | Real-time collaboration (Hocuspocus / Yjs)
    |--------------------------------------------------------------------------
    */
    'collaboration' => [
        'enabled' => true,
        'server_url' => env('DOCVAULT_COLLAB_URL', 'ws://localhost:1234'),
        'app_id' => env('DOCVAULT_COLLAB_APP_ID'),
        'secret' => env('DOCVAULT_COLLAB_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'max_document_size_kb' => 5120,
];
