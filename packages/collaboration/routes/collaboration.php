<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Collaboration\Http\Controllers\Collaboration\CreateCollaborationSessionController;
use Vaulter\Collaboration\Http\Controllers\Collaboration\HubAuthenticateController;
use Vaulter\Collaboration\Http\Controllers\Collaboration\HubLoadController;
use Vaulter\Collaboration\Http\Controllers\Collaboration\HubStoreController;
use Vaulter\Collaboration\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Configuration\RoutesConfig;

/*
|--------------------------------------------------------------------------
| Vaulter REST v1 — collaboration module
|--------------------------------------------------------------------------
| Mounted under {routes.prefix}/v1 and named vaulter.v1.*. Loaded only while the collaboration
| module is active; the module middleware answers 404 for a cached route of an inactive module.
|
| The session endpoint is a user-facing route of a document (it lives under documents/{node}, the
| URL the documents package reserves for the content of a document). The Hocuspocus hub's
| server-to-server calls carry a room JWT and a body signature instead of a user session, so they
| live on their own stack — never on the authenticated one.
*/

$routes = app(RoutesConfig::class);
$id = '[A-Za-z0-9_-]+';

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware($routes->authenticatedMiddleware())
    ->where(['node' => $id])
    ->group(static function (): void {
        Route::post('documents/{node}/collaboration/session', CreateCollaborationSessionController::class)->name('documents.collaboration.session');
    });

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware([...$routes->hubMiddleware(), 'throttle:vaulter-public', ResolveCollaborationCall::class])
    ->group(static function (): void {
        Route::post('collaboration/hub/authenticate', HubAuthenticateController::class)->name('collaboration.hub.authenticate');
        Route::post('collaboration/hub/load', HubLoadController::class)->name('collaboration.hub.load');
        Route::post('collaboration/hub/store', HubStoreController::class)->name('collaboration.hub.store');
    });
