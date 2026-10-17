<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Drive\Http\Controllers\Access\CreateGrantController;
use Vaulter\Drive\Http\Controllers\Access\ListGrantsController;
use Vaulter\Drive\Http\Controllers\Access\RestoreInheritanceController;
use Vaulter\Drive\Http\Controllers\Access\RestrictInheritanceController;
use Vaulter\Drive\Http\Controllers\Access\RevokeGrantController;
use Vaulter\Drive\Http\Controllers\Activity\ListDriveActivityController;
use Vaulter\Drive\Http\Controllers\Activity\ListNodeActivityController;
use Vaulter\Drive\Http\Controllers\Changes\ListChangesController;
use Vaulter\Drive\Http\Controllers\Changes\ShowChangesStartController;
use Vaulter\Drive\Http\Controllers\Sharing\CreateShareController;
use Vaulter\Drive\Http\Controllers\Sharing\DownloadPublicShareController;
use Vaulter\Drive\Http\Controllers\Sharing\ListPublicShareNodesController;
use Vaulter\Drive\Http\Controllers\Sharing\ListSharesController;
use Vaulter\Drive\Http\Controllers\Sharing\RevokeShareController;
use Vaulter\Drive\Http\Controllers\Sharing\RotateShareController;
use Vaulter\Drive\Http\Controllers\Sharing\ShowPublicShareController;
use Vaulter\Drive\Http\Controllers\Sharing\UpdateShareController;
use Vaulter\Http\Middleware\ResolveShareToken;

/*
|--------------------------------------------------------------------------
| Vaulter REST v1 — the drive routes located in core (D39)
|--------------------------------------------------------------------------
| Grants, shares, activity and the change feed: mounted under {routes.prefix}/v1 and
| named vaulter.v1.*. Route parameters are plain ids: no route-model binding; each
| controller resolves the drive through HttpResourceResolver (tenant-scoped) and calls
| the operations, which authorize and check that a grant or share belongs to its node.
| P4 moves the file into the drive package.
*/

$routes = app(RoutesConfig::class);
$id = '[A-Za-z0-9_-]+';

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware($routes->authenticatedMiddleware())
    ->where(['node' => $id, 'drive' => $id, 'grant' => $id, 'share' => $id])
    ->group(static function (): void {
        Route::get('nodes/{node}/grants', ListGrantsController::class)->name('grants.index');
        Route::post('nodes/{node}/grants', CreateGrantController::class)->name('grants.store');
        Route::delete('nodes/{node}/grants/{grant}', RevokeGrantController::class)->name('grants.destroy');
        Route::post('nodes/{node}/inheritance/restrict', RestrictInheritanceController::class)->name('access.inheritance.restrict');
        Route::post('nodes/{node}/inheritance/restore', RestoreInheritanceController::class)->name('access.inheritance.restore');

        Route::get('nodes/{node}/shares', ListSharesController::class)->name('shares.index');
        Route::post('nodes/{node}/shares', CreateShareController::class)->name('shares.store');
        Route::patch('nodes/{node}/shares/{share}', UpdateShareController::class)->name('shares.update');
        Route::post('nodes/{node}/shares/{share}/rotate', RotateShareController::class)->name('shares.rotate');
        Route::delete('nodes/{node}/shares/{share}', RevokeShareController::class)->name('shares.destroy');

        Route::get('drives/{drive}/activity', ListDriveActivityController::class)->name('activity.drive');
        Route::get('nodes/{node}/activity', ListNodeActivityController::class)->name('activity.node');

        Route::get('drives/{drive}/changes/start', ShowChangesStartController::class)->name('changes.start');
        Route::get('drives/{drive}/changes', ListChangesController::class)->name('changes.index');
    });

/*
| Public share links: no authentication of their own; ResolveShareToken turns the token
| into the link's role and node-set (throttled first, so token guessing is limited).
| Every response is private, no-store and Referrer-Policy: no-referrer.
*/
Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware([...$routes->publicMiddleware(), 'throttle:vaulter-public', ResolveShareToken::class])
    ->where(['token' => $id])
    ->group(static function (): void {
        Route::get('shares/{token}', ShowPublicShareController::class)->name('public-shares.show');
        Route::get('shares/{token}/nodes', ListPublicShareNodesController::class)->name('public-shares.nodes');
        Route::get('shares/{token}/download', DownloadPublicShareController::class)->name('public-shares.download');
    });
