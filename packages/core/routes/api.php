<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Vaulter\Http\Controllers\BulkDocumentController;
use Vaulter\Http\Controllers\CollaborationController;
use Vaulter\Http\Controllers\CommentController;
use Vaulter\Http\Controllers\DocumentController;
use Vaulter\Http\Controllers\Drive\DownloadController;
use Vaulter\Http\Controllers\Drive\NodeController;
use Vaulter\Http\Controllers\Drive\StorageController;
use Vaulter\Http\Controllers\ExportController;
use Vaulter\Http\Controllers\FolderController;
use Vaulter\Http\Controllers\PermissionController;
use Vaulter\Http\Controllers\ShareController;
use Vaulter\Http\Controllers\VersionController;
use Vaulter\Support\Config;

/*
|--------------------------------------------------------------------------
| Vaulter API routes
|--------------------------------------------------------------------------
| Mounted under the configured prefix (default: api/vaulter).
*/

Route::prefix(Config::routePrefix())
    ->middleware(Config::middleware())
    ->group(static function (): void {
        // Documents
        Route::get('documents', [DocumentController::class, 'index']); // supports ?q= search
        Route::get('documents/trash', [DocumentController::class, 'trash']); // before /{id}
        Route::post('documents/bulk', BulkDocumentController::class);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::patch('documents/{id}', [DocumentController::class, 'update']);
        Route::delete('documents/{id}', [DocumentController::class, 'destroy']);
        Route::delete('documents/{id}/force', [DocumentController::class, 'forceDestroy']);
        Route::post('documents/{id}/restore', [DocumentController::class, 'restore']);
        Route::patch('documents/{id}/move', [DocumentController::class, 'move']);
        Route::post('documents/{id}/duplicate', [DocumentController::class, 'duplicate']);
        Route::get('documents/{documentId}/export', [ExportController::class, 'show']);

        // Versions
        Route::get('documents/{documentId}/versions', [VersionController::class, 'index']);
        Route::post('documents/{documentId}/versions', [VersionController::class, 'store']);
        Route::post('documents/{documentId}/versions/{versionId}/restore', [VersionController::class, 'restore']);

        // Permissions
        Route::get('documents/{documentId}/permissions', [PermissionController::class, 'index']);
        Route::post('documents/{documentId}/permissions', [PermissionController::class, 'store']);
        Route::delete('documents/{documentId}/permissions/{permissionId}', [PermissionController::class, 'destroy']);

        // Comments
        Route::get('documents/{documentId}/comments', [CommentController::class, 'index']);
        Route::post('documents/{documentId}/comments', [CommentController::class, 'store']);
        Route::post('documents/{documentId}/comments/{commentId}/resolve', [CommentController::class, 'resolve']);
        Route::delete('documents/{documentId}/comments/{commentId}', [CommentController::class, 'destroy']);

        // Share links
        Route::get('documents/{documentId}/shares', [ShareController::class, 'index']);
        Route::post('documents/{documentId}/shares', [ShareController::class, 'store']);
        Route::delete('documents/{documentId}/shares/{shareId}', [ShareController::class, 'destroy']);
        Route::get('shares/{token}', [ShareController::class, 'resolve']);

        // Collaboration
        Route::post('documents/{documentId}/collab/token', [CollaborationController::class, 'token']);
        Route::post('documents/{documentId}/collab/sync', [CollaborationController::class, 'sync']);

        // Folders
        Route::get('folders', [FolderController::class, 'index']);
        Route::post('folders', [FolderController::class, 'store']);
        Route::patch('folders/{id}', [FolderController::class, 'update']);
        Route::delete('folders/{id}', [FolderController::class, 'destroy']);

        /*
        |----------------------------------------------------------------------
        | Drive (M1c) — the unified v_nodes file/folder tree
        |----------------------------------------------------------------------
        | {storage} and {node} resolve the swappable Node/Storage models via the
        | Route::bind registered in the service provider (config('vaulter.models.*')).
        | SubstituteBindings is applied explicitly so the route-model binding runs
        | even when a host trims its api middleware group (it is idempotent when the
        | host's group already includes it).
        |
        | Storage scope: storage-collection endpoints (index/search/trash/folders/
        | uploads) carry the {storage} id in the path; node-scoped endpoints derive
        | the storage from the bound node. The fixed sub-segments (search, trash,
        | force, download) sit before the catch-all /{node} so they never shadow it.
        */
        Route::middleware(SubstituteBindings::class)->group(static function (): void {
            Route::get('storages/{storage}', [StorageController::class, 'show']);

            Route::get('storages/{storage}/nodes', [NodeController::class, 'index']);
            Route::get('storages/{storage}/search', [NodeController::class, 'search']);
            Route::get('storages/{storage}/trash', [NodeController::class, 'trashed']);
            Route::post('storages/{storage}/folders', [NodeController::class, 'storeFolder']);
            Route::post('storages/{storage}/uploads', [NodeController::class, 'upload']);

            Route::get('nodes/{node}', [NodeController::class, 'show']);
            Route::patch('nodes/{node}', [NodeController::class, 'rename']);
            Route::post('nodes/{node}/move', [NodeController::class, 'move']);
            Route::post('nodes/{node}/copy', [NodeController::class, 'copy']);
            Route::post('nodes/{node}/restore', [NodeController::class, 'restore']);
            Route::delete('nodes/{node}/force', [NodeController::class, 'forceDelete']);
            Route::delete('nodes/{node}', [NodeController::class, 'trash']);

            Route::get('nodes/{node}/download', [DownloadController::class, 'current']);
            Route::get('nodes/{node}/versions/{version}/download', [DownloadController::class, 'version']);
        });
    });
