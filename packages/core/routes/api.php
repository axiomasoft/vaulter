<?php

declare(strict_types=1);

use DocVault\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DocVault API routes
|--------------------------------------------------------------------------
| Mounted under the configured prefix (default: api/docvault). Only the
| document CRUD endpoints are wired so far; versions, folders, permissions
| and the collaboration token endpoint follow in later milestones
| (see docvault-spec.md §7).
*/

Route::prefix((string) config('docvault.route_prefix', 'api/docvault'))
    ->middleware((array) config('docvault.middleware', ['api']))
    ->group(static function (): void {
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::patch('documents/{id}', [DocumentController::class, 'update']);
        Route::delete('documents/{id}', [DocumentController::class, 'destroy']);

        // TODO (M1+): documents/{id}/versions, folders, documents/{id}/permissions,
        //             documents/{id}/collab/token — see docvault-spec.md §7.
    });
