<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Drive\Http\Controllers\Drives\ArchiveDriveController;
use Vaulter\Drive\Http\Controllers\Drives\DeleteDriveController;
use Vaulter\Drive\Http\Controllers\Drives\ShowDriveController;
use Vaulter\Drive\Http\Controllers\Drives\UnarchiveDriveController;
use Vaulter\Drive\Http\Controllers\Files\AddFileRevisionController;
use Vaulter\Drive\Http\Controllers\Files\DeleteFileRevisionController;
use Vaulter\Drive\Http\Controllers\Files\DownloadFileController;
use Vaulter\Drive\Http\Controllers\Files\DownloadFileRevisionController;
use Vaulter\Drive\Http\Controllers\Files\ListFileRevisionsController;
use Vaulter\Drive\Http\Controllers\Files\RestoreFileRevisionController;
use Vaulter\Drive\Http\Controllers\Files\UpdateFileRevisionController;
use Vaulter\Drive\Http\Controllers\Files\UploadFileController;
use Vaulter\Drive\Http\Controllers\Folders\CreateFolderController;
use Vaulter\Drive\Http\Controllers\Nodes\CopyNodeController;
use Vaulter\Drive\Http\Controllers\Nodes\ListChildrenController;
use Vaulter\Drive\Http\Controllers\Nodes\ListStarredController;
use Vaulter\Drive\Http\Controllers\Nodes\MoveNodeController;
use Vaulter\Drive\Http\Controllers\Nodes\ShowAbilitiesController;
use Vaulter\Drive\Http\Controllers\Nodes\ShowNodeController;
use Vaulter\Drive\Http\Controllers\Nodes\StarNodeController;
use Vaulter\Drive\Http\Controllers\Nodes\UnstarNodeController;
use Vaulter\Drive\Http\Controllers\Nodes\UpdateNodeController;
use Vaulter\Drive\Http\Controllers\Nodes\UpdateNodePropertiesController;
use Vaulter\Drive\Http\Controllers\Search\SearchNodesController;
use Vaulter\Drive\Http\Controllers\Shared\ListSharedWithMeController;
use Vaulter\Drive\Http\Controllers\Trash\EmptyTrashController;
use Vaulter\Drive\Http\Controllers\Trash\ListTrashController;
use Vaulter\Drive\Http\Controllers\Trash\PurgeNodeController;
use Vaulter\Drive\Http\Controllers\Trash\RestoreNodeController;
use Vaulter\Drive\Http\Controllers\Trash\TrashNodeController;
use Vaulter\Drive\Http\Controllers\Uploads\CreateUploadController;
use Vaulter\Drive\Http\Controllers\Uploads\S3MultipartProtocolController;
use Vaulter\Drive\Http\Controllers\Uploads\TusProtocolController;

/*
|--------------------------------------------------------------------------
| Vaulter REST v1 — drive routes
|--------------------------------------------------------------------------
| Mounted under {routes.prefix}/v1 and named vaulter.v1.*, on the authenticated stack.
| Route parameters are plain ids: each controller resolves the drive through
| HttpResourceResolver (tenant-scoped) and calls the operations, which authorize. The
| routes are always registered while the package is installed; a disabled feature is
| refused by the operations (403 profile_restriction on something the caller can see),
| so `route:cache` never freezes a feature flag.
|
| Operations shared by every node kind live under nodes/{node}; operations on file
| content live under files/{node}, so the documents package never shares a URL with
| this one.
*/

$routes = app(RoutesConfig::class);
$id = '[A-Za-z0-9_-]+';

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware($routes->authenticatedMiddleware())
    ->where(['drive' => $id, 'node' => $id, 'revision' => $id, 'upload' => $id])
    ->group(static function (): void {
        Route::get('drives/{drive}', ShowDriveController::class)->name('drives.show');
        Route::post('drives/{drive}/archive', ArchiveDriveController::class)->name('drives.archive');
        Route::post('drives/{drive}/unarchive', UnarchiveDriveController::class)->name('drives.unarchive');
        Route::delete('drives/{drive}', DeleteDriveController::class)->name('drives.destroy');

        Route::get('drives/{drive}/nodes', ListChildrenController::class)->name('nodes.index');
        Route::get('drives/{drive}/search', SearchNodesController::class)->name('nodes.search');
        Route::get('drives/{drive}/starred', ListStarredController::class)->name('nodes.starred');
        Route::get('drives/{drive}/trash', ListTrashController::class)->name('trash.index');
        Route::delete('drives/{drive}/trash', EmptyTrashController::class)->name('trash.empty');
        Route::post('drives/{drive}/folders', CreateFolderController::class)->name('folders.store');
        Route::post('drives/{drive}/files', UploadFileController::class)->name('files.store');

        Route::get('shared', ListSharedWithMeController::class)->name('shared.index');

        Route::get('nodes/{node}', ShowNodeController::class)->name('nodes.show');
        Route::patch('nodes/{node}', UpdateNodeController::class)->name('nodes.update');
        Route::patch('nodes/{node}/properties', UpdateNodePropertiesController::class)->name('nodes.properties.update');
        Route::put('nodes/{node}/star', StarNodeController::class)->name('nodes.star');
        Route::delete('nodes/{node}/star', UnstarNodeController::class)->name('nodes.unstar');
        Route::post('nodes/{node}/move', MoveNodeController::class)->name('nodes.move');
        Route::post('nodes/{node}/copy', CopyNodeController::class)->name('nodes.copy');
        Route::post('nodes/{node}/trash', TrashNodeController::class)->name('nodes.trash');
        Route::post('nodes/{node}/restore', RestoreNodeController::class)->name('nodes.restore');
        Route::delete('nodes/{node}', PurgeNodeController::class)->name('nodes.purge');
        Route::get('nodes/{node}/abilities', ShowAbilitiesController::class)->name('nodes.abilities');

        Route::get('files/{node}/download', DownloadFileController::class)->name('files.download');
        Route::get('files/{node}/revisions', ListFileRevisionsController::class)->name('files.revisions.index');
        Route::post('files/{node}/revisions', AddFileRevisionController::class)->name('files.revisions.store');
        Route::get('files/{node}/revisions/{revision}/download', DownloadFileRevisionController::class)->name('files.revisions.download');
        Route::post('files/{node}/revisions/{revision}/restore', RestoreFileRevisionController::class)->name('files.revisions.restore');
        Route::patch('files/{node}/revisions/{revision}', UpdateFileRevisionController::class)->name('files.revisions.update');
        Route::delete('files/{node}/revisions/{revision}', DeleteFileRevisionController::class)->name('files.revisions.destroy');

        /*
        | Resumable uploads. The tus and S3-multipart protocols keep their own acts and
        | headers (Tus-Resumable, Upload-Offset, part ETags); errors still answer as
        | problem+json. Throttled by the package's vaulter-uploads limiter, keyed by actor.
        */
        Route::middleware('throttle:vaulter-uploads')->group(static function (): void {
            Route::post('drives/{drive}/uploads', CreateUploadController::class)->name('uploads.store');
            Route::get('uploads/{upload}', [TusProtocolController::class, 'status'])->name('uploads.show');
            Route::match(['HEAD'], 'uploads/{upload}', [TusProtocolController::class, 'head'])->name('uploads.tus.head');
            Route::patch('uploads/{upload}', [TusProtocolController::class, 'patch'])->name('uploads.tus.patch');
            Route::delete('uploads/{upload}', [TusProtocolController::class, 'abort'])->name('uploads.tus.destroy');
            // tus discovery answers for any upload id without resolving a session.
            Route::options('uploads/{upload}', [TusProtocolController::class, 'options'])->name('uploads.tus.options');
            Route::post('uploads/{upload}/parts', [S3MultipartProtocolController::class, 'parts'])->name('uploads.parts.store');
            Route::post('uploads/{upload}/complete', [S3MultipartProtocolController::class, 'complete'])->name('uploads.complete');
        });
    });
