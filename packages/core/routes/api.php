<?php

declare(strict_types=1);

use DocVault\Http\Controllers\CollaborationController;
use DocVault\Http\Controllers\DocumentController;
use DocVault\Http\Controllers\FolderController;
use DocVault\Http\Controllers\PermissionController;
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
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::patch('documents/{id}', [DocumentController::class, 'update']);
        Route::delete('documents/{id}', [DocumentController::class, 'destroy']);
        Route::post('documents/{id}/restore', [DocumentController::class, 'restore']);

        // Versions
        Route::get('documents/{documentId}/versions', [VersionController::class, 'index']);
        Route::post('documents/{documentId}/versions', [VersionController::class, 'store']);
        Route::post('documents/{documentId}/versions/{versionId}/restore', [VersionController::class, 'restore']);

        // Permissions
        Route::get('documents/{documentId}/permissions', [PermissionController::class, 'index']);
        Route::post('documents/{documentId}/permissions', [PermissionController::class, 'store']);
        Route::delete('documents/{documentId}/permissions/{permissionId}', [PermissionController::class, 'destroy']);

        // Collaboration
        Route::post('documents/{documentId}/collab/token', [CollaborationController::class, 'token']);

        // Folders
        Route::get('folders', [FolderController::class, 'index']);
        Route::post('folders', [FolderController::class, 'store']);
        Route::patch('folders/{id}', [FolderController::class, 'update']);
        Route::delete('folders/{id}', [FolderController::class, 'destroy']);
    });
