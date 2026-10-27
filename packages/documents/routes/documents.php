<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Documents\Http\Controllers\Comments\AddCommentController;
use Vaulter\Documents\Http\Controllers\Comments\DeleteCommentController;
use Vaulter\Documents\Http\Controllers\Comments\ListCommentsController;
use Vaulter\Documents\Http\Controllers\Comments\ResolveCommentController;
use Vaulter\Documents\Http\Controllers\Documents\CreateCollaborationSessionController;
use Vaulter\Documents\Http\Controllers\Documents\CreateDocumentController;
use Vaulter\Documents\Http\Controllers\Documents\ExportDocumentController;
use Vaulter\Documents\Http\Controllers\Documents\ShowDocumentContentController;
use Vaulter\Documents\Http\Controllers\Documents\UpdateDocumentContentController;
use Vaulter\Documents\Http\Controllers\Revisions\CreateDocumentRevisionController;
use Vaulter\Documents\Http\Controllers\Revisions\ListDocumentRevisionsController;
use Vaulter\Documents\Http\Controllers\Revisions\RestoreDocumentRevisionController;
use Vaulter\Documents\Http\Controllers\Revisions\ShowDocumentRevisionController;
use Vaulter\Documents\Http\Controllers\Revisions\UpdateDocumentRevisionController;
use Vaulter\Http\Middleware\ReadIdempotencyKey;

/*
|--------------------------------------------------------------------------
| Vaulter REST v1 — documents routes
|--------------------------------------------------------------------------
| Mounted under {routes.prefix}/v1 and named vaulter.v1.*. Route parameters are plain
| ids: each controller resolves the drive through HttpResourceResolver (tenant-scoped)
| and calls the operations, which authorize. The routes are always registered while the
| module is active; a disabled feature (comments) is refused by the operations, so
| `route:cache` never freezes a feature flag. Session issuance is protocol-neutral.
|
| Operations on the content of a document live under documents/{node}; the operations
| every node kind shares (comments) live under nodes/{node}, so this package never
| shares a URL with the drive package.
*/

$routes = app(RoutesConfig::class);
$id = '[A-Za-z0-9_-]+';

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware($routes->authenticatedMiddleware())
    ->where(['drive' => $id, 'node' => $id, 'revision' => $id, 'comment' => $id, 'format' => '[A-Za-z0-9._-]+'])
    ->group(static function (): void {
        Route::post('documents/{node}/collaboration/session', CreateCollaborationSessionController::class)->name('documents.collaboration.session');
        Route::post('drives/{drive}/documents', CreateDocumentController::class)->name('documents.store')->middleware(ReadIdempotencyKey::ALIAS);

        Route::get('documents/{node}/content', ShowDocumentContentController::class)->name('documents.content.show');
        Route::put('documents/{node}/content', UpdateDocumentContentController::class)->name('documents.content.update');
        Route::get('documents/{node}/revisions', ListDocumentRevisionsController::class)->name('documents.revisions.index');
        Route::post('documents/{node}/revisions', CreateDocumentRevisionController::class)->name('documents.revisions.store')->middleware(ReadIdempotencyKey::ALIAS);
        Route::get('documents/{node}/revisions/{revision}', ShowDocumentRevisionController::class)->name('documents.revisions.show');
        Route::post('documents/{node}/revisions/{revision}/restore', RestoreDocumentRevisionController::class)->name('documents.revisions.restore')->middleware(ReadIdempotencyKey::ALIAS);
        Route::patch('documents/{node}/revisions/{revision}', UpdateDocumentRevisionController::class)->name('documents.revisions.update');
        Route::get('documents/{node}/export/{format}', ExportDocumentController::class)->name('documents.export');

        Route::get('nodes/{node}/comments', ListCommentsController::class)->name('comments.index');
        Route::post('nodes/{node}/comments', AddCommentController::class)->name('comments.store')->middleware(ReadIdempotencyKey::ALIAS);
        Route::post('nodes/{node}/comments/{comment}/resolve', ResolveCommentController::class)->name('comments.resolve')->middleware(ReadIdempotencyKey::ALIAS);
        Route::delete('nodes/{node}/comments/{comment}', DeleteCommentController::class)->name('comments.destroy')->middleware(ReadIdempotencyKey::ALIAS);
    });
