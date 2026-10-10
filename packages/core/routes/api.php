<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Http\Controllers\Access\CreateGrantController;
use Vaulter\Http\Controllers\Access\ListGrantsController;
use Vaulter\Http\Controllers\Access\RestoreInheritanceController;
use Vaulter\Http\Controllers\Access\RestrictInheritanceController;
use Vaulter\Http\Controllers\Access\RevokeGrantController;
use Vaulter\Http\Controllers\Activity\ListDriveActivityController;
use Vaulter\Http\Controllers\Activity\ListNodeActivityController;
use Vaulter\Http\Controllers\Changes\ListChangesController;
use Vaulter\Http\Controllers\Changes\ShowChangesStartController;
use Vaulter\Http\Controllers\Sharing\CreateShareController;
use Vaulter\Http\Controllers\Sharing\DownloadPublicShareController;
use Vaulter\Http\Controllers\Sharing\ListPublicShareNodesController;
use Vaulter\Http\Controllers\Sharing\ListSharesController;
use Vaulter\Http\Controllers\Sharing\RevokeShareController;
use Vaulter\Http\Controllers\Sharing\RotateShareController;
use Vaulter\Http\Controllers\Sharing\ShowPublicShareController;
use Vaulter\Http\Controllers\Sharing\UpdateShareController;
use Vaulter\Http\Controllers\Webhooks\DeactivateWebhookEndpointController;
use Vaulter\Http\Controllers\Webhooks\DeleteWebhookEndpointController;
use Vaulter\Http\Controllers\Webhooks\ListWebhookDeliveriesController;
use Vaulter\Http\Controllers\Webhooks\ListWebhookEndpointsController;
use Vaulter\Http\Controllers\Webhooks\RedeliverWebhookController;
use Vaulter\Http\Controllers\Webhooks\RegisterWebhookEndpointController;
use Vaulter\Http\Controllers\Webhooks\RotateWebhookSecretController;
use Vaulter\Http\Controllers\Webhooks\ShowWebhookEndpointController;
use Vaulter\Http\Controllers\Webhooks\UpdateWebhookEndpointController;
use Vaulter\Http\Middleware\ResolveShareToken;

/*
|--------------------------------------------------------------------------
| Vaulter REST v1 — core routes
|--------------------------------------------------------------------------
| Mounted under {routes.prefix}/v1 and named vaulter.v1.*. Route parameters are plain
| ids: no route-model binding; each controller resolves the drive through
| HttpResourceResolver (tenant-scoped) and calls the operations, which authorize and
| check that a grant or share belongs to its node. The drive and documents packages
| register their own v1 routes.
*/

$routes = app(RoutesConfig::class);
$id = '[A-Za-z0-9_-]+';

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware($routes->authenticatedMiddleware())
    ->where(['node' => $id, 'drive' => $id, 'grant' => $id, 'share' => $id, 'endpoint' => $id, 'delivery' => $id])
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

        // The global admin surface for webhook subscribers; mounted only with the
        // feature, because its tables exist only then.
        if (app(VaulterConfig::class)->featureEnabled('webhooks')) {
            Route::get('webhook-endpoints', ListWebhookEndpointsController::class)->name('webhook-endpoints.index');
            Route::post('webhook-endpoints', RegisterWebhookEndpointController::class)->name('webhook-endpoints.store');
            Route::get('webhook-endpoints/{endpoint}', ShowWebhookEndpointController::class)->name('webhook-endpoints.show');
            Route::patch('webhook-endpoints/{endpoint}', UpdateWebhookEndpointController::class)->name('webhook-endpoints.update');
            Route::post('webhook-endpoints/{endpoint}/deactivate', DeactivateWebhookEndpointController::class)->name('webhook-endpoints.deactivate');
            Route::post('webhook-endpoints/{endpoint}/rotate-secret', RotateWebhookSecretController::class)->name('webhook-endpoints.rotate-secret');
            Route::delete('webhook-endpoints/{endpoint}', DeleteWebhookEndpointController::class)->name('webhook-endpoints.destroy');
            Route::get('webhook-endpoints/{endpoint}/deliveries', ListWebhookDeliveriesController::class)->name('webhook-deliveries.index');
            Route::post('webhook-endpoints/{endpoint}/deliveries/{delivery}/redeliver', RedeliverWebhookController::class)->name('webhook-deliveries.redeliver');
        }
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
