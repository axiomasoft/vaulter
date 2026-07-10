<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Vaulter\Drive\Http\Controllers\DownloadController;
use Vaulter\Drive\Http\Controllers\NodeController;
use Vaulter\Drive\Http\Controllers\StorageController;
use Vaulter\Drive\Uploads\Http\Controllers\S3MultipartController;
use Vaulter\Drive\Uploads\Http\Controllers\TusUploadController;
use Vaulter\Support\Config;

/*
|--------------------------------------------------------------------------
| Vaulter Drive API routes (P2 #5 / ADR-0015, D1)
|--------------------------------------------------------------------------
| The file/folder tree + resumable-upload surface, extracted from core's
| routes/api.php into the vaulter-drive package. Mounted under the SAME configured
| prefix (default: api/vaulter) and middleware as the kernel routes — Laravel merges
| the two group registrations. The {storage} and {node} route-model bindings are
| registered by the CORE provider (Node/Storage are kernel models); these routes
| reuse them. All paths are distinct literals / differ in segment count from the
| kernel + documents routes, so cross-file registration order never shadows them.
*/

Route::prefix(Config::routePrefix())
    ->middleware(Config::middleware())
    ->group(static function (): void {
        Route::middleware(SubstituteBindings::class)->group(static function (): void {
            // "Shared with me" (§2.6 / H.2) — CROSS-STORAGE: not storage-bound, so it
            // sits at the top level. Gated on the 'grants' feature; the fixed segment
            // sits before the catch-all /{node} routes so it never shadows them.
            if (Config::featureEnabled('grants')) {
                Route::get('shared', [NodeController::class, 'shared']);
            }

            Route::get('storages/{storage}', [StorageController::class, 'show']);

            Route::get('storages/{storage}/nodes', [NodeController::class, 'index']);
            Route::get('storages/{storage}/search', [NodeController::class, 'search']);
            Route::get('storages/{storage}/trash', [NodeController::class, 'trashed']);
            Route::post('storages/{storage}/folders', [NodeController::class, 'storeFolder']);
            Route::post('storages/{storage}/uploads', [NodeController::class, 'upload']);

            /*
            |------------------------------------------------------------------
            | Resumable uploads (§2.8 / D16 / M3a)
            |------------------------------------------------------------------
            | Large files upload resumably, then the assembled staged bytes go
            | through the SAME race-free BlobStore.commit (H.3). Gated on the
            | 'uploads' feature. {upload_id} is the client UNIQUE handle (a plain
            | scalar, NOT route-model bound — the controller resolves the session
            | and asserts it belongs to an authorized target). tus group: create
            | (POST), HEAD (Upload-Offset), PATCH (chunk), DELETE (abort), status
            | (GET). S3 group: parts (POST), complete (POST). Authorized via
            | AccessResolver — Create in the target folder / Edit for a new version.
            |
            | Rate-limited (P1.3 / F-P1.8-01) by the package's own `vaulter-uploads`
            | named limiter (config('vaulter.rate_limits.uploads'), keyed by actor id —
            | falling back to IP when unauthenticated) — a DoS lever distinct from the
            | max-active-sessions cap (F-P1.4-09, enforced in UploadSessionManager).
            */
            if (Config::featureEnabled('uploads')) {
                Route::middleware('throttle:vaulter-uploads')->group(static function (): void {
                    // tus.io CORE — create lives under the storage; the resumable ops
                    // address the session by its upload_id handle.
                    Route::post('storages/{storage}/resumable-uploads', [TusUploadController::class, 'create']);

                    Route::get('resumable-uploads/{upload_id}', [TusUploadController::class, 'status']);
                    Route::match(['head'], 'resumable-uploads/{upload_id}', [TusUploadController::class, 'head']);
                    Route::patch('resumable-uploads/{upload_id}', [TusUploadController::class, 'patch']);
                    Route::delete('resumable-uploads/{upload_id}', [TusUploadController::class, 'abort']);

                    // S3-style multipart — parts + complete on the same session handle.
                    Route::post('resumable-uploads/{upload_id}/parts', [S3MultipartController::class, 'parts']);
                    Route::post('resumable-uploads/{upload_id}/complete', [S3MultipartController::class, 'complete']);
                });
            }

            // The unified node tree (§2.2). The catch-all /{node} routes sit AFTER the
            // fixed sub-segments above (and after the kernel/documents fixed segments in
            // routes/api.php — different segment counts, so order across files is safe).
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
