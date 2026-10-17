<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Collaboration\Http\Controllers\Collaboration\CreateCollaborationSessionController;
use Vaulter\Collaboration\Http\Controllers\Collaboration\HubAppendController;
use Vaulter\Collaboration\Http\Controllers\Collaboration\HubAuthenticateController;
use Vaulter\Collaboration\Http\Controllers\Collaboration\HubCheckpointController;
use Vaulter\Collaboration\Http\Controllers\Collaboration\HubCompactionLeaseController;
use Vaulter\Collaboration\Http\Controllers\Collaboration\HubLoadController;
use Vaulter\Collaboration\Http\Middleware\LimitHubAppend;
use Vaulter\Collaboration\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Collaboration\Http\Middleware\VerifyHubSignature;
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
    });

// Protocol 2 append: the body size is checked by its Content-Length before the signature check reads the body.
Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware([...$routes->hubMiddleware(), 'throttle:vaulter-public', LimitHubAppend::class, ResolveCollaborationCall::class])
    ->group(static function (): void {
        Route::post('collaboration/hub/append', HubAppendController::class)->name('collaboration.hub.append');
    });

// Protocol 2 compaction: the hub's worker calls for itself, not for a room connection, so the body signature alone
// authenticates them (no room token); the size gate runs first here too.
Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware([...$routes->hubMiddleware(), 'throttle:vaulter-public'])
    ->group(static function (): void {
        Route::post('collaboration/hub/compaction/lease', HubCompactionLeaseController::class)
            ->middleware([LimitHubAppend::class, VerifyHubSignature::class])
            ->name('collaboration.hub.compaction.lease');
        Route::post('collaboration/hub/checkpoint', HubCheckpointController::class)
            ->middleware([LimitHubAppend::class.':'.LimitHubAppend::CHECKPOINT, VerifyHubSignature::class])
            ->name('collaboration.hub.checkpoint');
    });
