<?php

declare(strict_types=1);

use DocVault\Http\Controllers\CollaborationController;
use DocVault\Http\Controllers\CommentController;
use DocVault\Http\Controllers\DocumentController;
use DocVault\Http\Controllers\ExportController;
use DocVault\Http\Controllers\FolderController;
use DocVault\Http\Controllers\PermissionController;
use DocVault\Http\Controllers\ShareController;
use DocVault\Http\Controllers\VersionController;
use DocVault\Support\Config;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DocVault API routes
|--------------------------------------------------------------------------
| Mounted under the configured prefix (default: api/docvault).
*/

Route::prefix(Config::routePrefix())
    ->middleware(Config::middleware())
    ->group(static function (): void {
        // Documents
        Route::get('documents', [DocumentController::class, 'index']); // supports ?q= search
        Route::get('documents/trash', [DocumentController::class, 'trash']); // before /{id}
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::patch('documents/{id}', [DocumentController::class, 'update']);
        Route::delete('documents/{id}', [DocumentController::class, 'destroy']);
        Route::delete('documents/{id}/force', [DocumentController::class, 'forceDestroy']);
        Route::post('documents/{id}/restore', [DocumentController::class, 'restore']);
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
    });
