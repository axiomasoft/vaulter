<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Http\Controllers\Collaboration\CreateCollaborationSessionController;
use Vaulter\Documents\Http\Controllers\Collaboration\HubAuthenticateController;
use Vaulter\Documents\Http\Controllers\Collaboration\HubLoadController;
use Vaulter\Documents\Http\Controllers\Collaboration\HubStoreController;
use Vaulter\Documents\Http\Controllers\Comments\AddCommentController;
use Vaulter\Documents\Http\Controllers\Comments\DeleteCommentController;
use Vaulter\Documents\Http\Controllers\Comments\ListCommentsController;
use Vaulter\Documents\Http\Controllers\Comments\ResolveCommentController;
use Vaulter\Documents\Http\Controllers\Documents\CreateDocumentController;
use Vaulter\Documents\Http\Controllers\Documents\ExportDocumentController;
use Vaulter\Documents\Http\Controllers\Documents\ShowDocumentContentController;
use Vaulter\Documents\Http\Controllers\Documents\UpdateDocumentContentController;
use Vaulter\Documents\Http\Controllers\Revisions\CreateDocumentRevisionController;
use Vaulter\Documents\Http\Controllers\Revisions\ListDocumentRevisionsController;
use Vaulter\Documents\Http\Controllers\Revisions\RestoreDocumentRevisionController;
use Vaulter\Documents\Http\Controllers\Revisions\ShowDocumentRevisionController;
use Vaulter\Documents\Http\Controllers\Revisions\UpdateDocumentRevisionController;
use Vaulter\Documents\Http\Middleware\ResolveCollaborationCall;

/*
|--------------------------------------------------------------------------
| Vaulter REST v1 — documents routes
|--------------------------------------------------------------------------
| Mounted under {routes.prefix}/v1 and named vaulter.v1.*. Route parameters are plain
| ids: each controller resolves the drive through HttpResourceResolver (tenant-scoped)
| and calls the operations, which authorize. The routes are always registered while the
| package is installed; a disabled feature (comments, collaboration) is refused by the
| operations, so `route:cache` never freezes a feature flag.
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
        Route::post('drives/{drive}/documents', CreateDocumentController::class)->name('documents.store');

        Route::get('documents/{node}/content', ShowDocumentContentController::class)->name('documents.content.show');
        Route::put('documents/{node}/content', UpdateDocumentContentController::class)->name('documents.content.update');
        Route::get('documents/{node}/revisions', ListDocumentRevisionsController::class)->name('documents.revisions.index');
        Route::post('documents/{node}/revisions', CreateDocumentRevisionController::class)->name('documents.revisions.store');
        Route::get('documents/{node}/revisions/{revision}', ShowDocumentRevisionController::class)->name('documents.revisions.show');
        Route::post('documents/{node}/revisions/{revision}/restore', RestoreDocumentRevisionController::class)->name('documents.revisions.restore');
        Route::patch('documents/{node}/revisions/{revision}', UpdateDocumentRevisionController::class)->name('documents.revisions.update');
        Route::get('documents/{node}/export/{format}', ExportDocumentController::class)->name('documents.export');
        Route::post('documents/{node}/collaboration/session', CreateCollaborationSessionController::class)->name('documents.collaboration.session');

        Route::get('nodes/{node}/comments', ListCommentsController::class)->name('comments.index');
        Route::post('nodes/{node}/comments', AddCommentController::class)->name('comments.store');
        Route::post('nodes/{node}/comments/{comment}/resolve', ResolveCommentController::class)->name('comments.resolve');
        Route::delete('nodes/{node}/comments/{comment}', DeleteCommentController::class)->name('comments.destroy');
    });

/*
| The Hocuspocus hub's server-to-server calls. They carry a room JWT and a body
| signature instead of a user session, so they live on their own stack — never on the
| authenticated one — and exist only while collaboration is on (the hub secret).
*/
if (app(DocumentsConfig::class)->collaborationEnabled()) {
    Route::prefix($routes->versionedPrefix())
        ->name('vaulter.v1.')
        ->middleware([...$routes->hubMiddleware(), 'throttle:vaulter-public', ResolveCollaborationCall::class])
        ->group(static function (): void {
            Route::post('collaboration/hub/authenticate', HubAuthenticateController::class)->name('collaboration.hub.authenticate');
            Route::post('collaboration/hub/load', HubLoadController::class)->name('collaboration.hub.load');
            Route::post('collaboration/hub/store', HubStoreController::class)->name('collaboration.hub.store');
        });
}
