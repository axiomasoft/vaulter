<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Http\Controllers\Webhooks\DeactivateWebhookEndpointController;
use Vaulter\Http\Controllers\Webhooks\DeleteWebhookEndpointController;
use Vaulter\Http\Controllers\Webhooks\ListWebhookDeliveriesController;
use Vaulter\Http\Controllers\Webhooks\ListWebhookEndpointsController;
use Vaulter\Http\Controllers\Webhooks\RedeliverWebhookController;
use Vaulter\Http\Controllers\Webhooks\RegisterWebhookEndpointController;
use Vaulter\Http\Controllers\Webhooks\RotateWebhookSecretController;
use Vaulter\Http\Controllers\Webhooks\ShowWebhookEndpointController;
use Vaulter\Http\Controllers\Webhooks\UpdateWebhookEndpointController;

/*
|--------------------------------------------------------------------------
| Vaulter REST v1 — webhooks module
|--------------------------------------------------------------------------
| The global admin surface for webhook subscribers, mounted under {routes.prefix}/v1 and
| named vaulter.v1.*. Loaded only while the webhooks module is active; the module
| middleware answers 404 for a cached route of an inactive module.
*/

$routes = app(RoutesConfig::class);
$id = '[A-Za-z0-9_-]+';

Route::prefix($routes->versionedPrefix())
    ->name('vaulter.v1.')
    ->middleware($routes->authenticatedMiddleware())
    ->where(['endpoint' => $id, 'delivery' => $id])
    ->group(static function (): void {
        Route::get('webhook-endpoints', ListWebhookEndpointsController::class)->name('webhook-endpoints.index');
        Route::post('webhook-endpoints', RegisterWebhookEndpointController::class)->name('webhook-endpoints.store');
        Route::get('webhook-endpoints/{endpoint}', ShowWebhookEndpointController::class)->name('webhook-endpoints.show');
        Route::patch('webhook-endpoints/{endpoint}', UpdateWebhookEndpointController::class)->name('webhook-endpoints.update');
        Route::post('webhook-endpoints/{endpoint}/deactivate', DeactivateWebhookEndpointController::class)->name('webhook-endpoints.deactivate');
        Route::post('webhook-endpoints/{endpoint}/rotate-secret', RotateWebhookSecretController::class)->name('webhook-endpoints.rotate-secret');
        Route::delete('webhook-endpoints/{endpoint}', DeleteWebhookEndpointController::class)->name('webhook-endpoints.destroy');
        Route::get('webhook-endpoints/{endpoint}/deliveries', ListWebhookDeliveriesController::class)->name('webhook-deliveries.index');
        Route::post('webhook-endpoints/{endpoint}/deliveries/{delivery}/redeliver', RedeliverWebhookController::class)->name('webhook-deliveries.redeliver');
    });
