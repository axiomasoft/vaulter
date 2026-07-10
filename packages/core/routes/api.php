<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Vaulter\Http\Controllers\Access\GrantController;
use Vaulter\Http\Controllers\Activity\ActivityController;
use Vaulter\Http\Controllers\Sharing\PublicShareController;
use Vaulter\Http\Controllers\Sharing\ShareController as NodeShareController;
use Vaulter\Http\Controllers\Webhooks\WebhookEndpointsController;
use Vaulter\Http\Middleware\ResolveShareToken;
use Vaulter\Support\Config;

/*
|--------------------------------------------------------------------------
| Vaulter API routes
|--------------------------------------------------------------------------
| Mounted under the configured prefix (default: api/vaulter).
*/

Route::prefix(Config::routePrefix())
    ->middleware(Config::middleware())
    ->group(static function (): void {
        /*
        |----------------------------------------------------------------------
        | Node sub-resources — kernel (Access/Sharing/Activity)
        |----------------------------------------------------------------------
        | {storage} and {node} resolve the swappable Node/Storage models via the
        | Route::bind registered in the service provider (config('vaulter.models.*')).
        | SubstituteBindings is applied explicitly so the route-model binding runs
        | even when a host trims its api middleware group (it is idempotent when the
        | host's group already includes it).
        |
        | The Drive file/folder tree + resumable uploads (the storage/{storage} listing,
        | node CRUD, download, tus/S3 endpoints) live in the vaulter-drive package's
        | routes/drive.php (P2 #5 / ADR-0015, D1). The Documents document/comment/collab
        | routes live in the vaulter-documents package's routes/documents.php (D2). The
        | fixed node sub-segments below (grants/shares/activity) differ in segment count
        | from both packages' catch-all /{node}, so cross-file registration order never
        | shadows them.
        */
        Route::middleware(SubstituteBindings::class)->group(static function (): void {
            // Append-only activity feed (§2.9 / M2c-activity). Recipients resolved at
            // READ by scoping to the storage/node — no notification rows. Authorized
            // via AccessResolver Ability::View on the storage root / node. The fixed
            // /activity segment sits before the vaulter-drive catch-all /{node}.
            Route::get('storages/{storage}/activity', [ActivityController::class, 'forStorage']);

            // Per-node ACL grants (§2.6 / H.2). The fixed /grants segment sits before
            // the catch-all /{node} so it never shadows the node routes above.
            Route::get('nodes/{node}/grants', [GrantController::class, 'index']);
            Route::post('nodes/{node}/grants', [GrantController::class, 'store']);
            Route::delete('nodes/{node}/grants', [GrantController::class, 'destroy']);

            // Bearer share-links — the AUTHENTICATED OWNER surface (§2.7 / M2c).
            // Authorized via AccessResolver Ability::Share on the node. create()
            // returns the raw token once; index() never exposes it. The fixed
            // /shares segment sits before the catch-all /{node}. The {share} id is a
            // plain scalar (not route-model bound) — the controller verifies it
            // belongs to the authorized node before revoking.
            Route::get('nodes/{node}/shares', [NodeShareController::class, 'index']);
            Route::post('nodes/{node}/shares', [NodeShareController::class, 'store']);
            Route::delete('nodes/{node}/shares/{share}', [NodeShareController::class, 'destroy']);

            // Per-node activity feed (§2.9 / M2c-activity). Authorized via
            // AccessResolver Ability::View on the node. The fixed /activity segment
            // sits before the catch-all /{node}.
            Route::get('nodes/{node}/activity', [ActivityController::class, 'forNode']);
        });

        /*
        |----------------------------------------------------------------------
        | Webhook-endpoint management (§2.11 / D14 / M3b)
        |----------------------------------------------------------------------
        | The GLOBAL admin surface for subscriber endpoints — register/list/update/
        | deactivate/delete. NOT node-scoped: every action is gated by the
        | WebhookEndpointPolicy manage ability (Gate::authorize in the controller).
        | register() returns the signing_secret exactly once. Gated on the
        | 'reliability' feature so the routes only mount when the outbox/webhook schema
        | exists. The {endpoint} id is a plain scalar (the model is swappable, not
        | route-model bound) — the controller resolves it via the read repository.
        */
        if (Config::featureEnabled('reliability')) {
            Route::get('webhook-endpoints', [WebhookEndpointsController::class, 'index']);
            Route::post('webhook-endpoints', [WebhookEndpointsController::class, 'store']);
            Route::patch('webhook-endpoints/{endpoint}', [WebhookEndpointsController::class, 'update']);
            Route::post('webhook-endpoints/{endpoint}/deactivate', [WebhookEndpointsController::class, 'deactivate']);
            Route::delete('webhook-endpoints/{endpoint}', [WebhookEndpointsController::class, 'destroy']);
        }

        /*
        |----------------------------------------------------------------------
        | Bearer share-links — the PUBLIC, token-resolved surface (§2.7 / M2c)
        |----------------------------------------------------------------------
        | The SEPARATE sharing channel. These routes carry NO auth middleware —
        | only ResolveShareToken, which hashes the {token}, resolves the LIVE
        | share, runs the timing-safe compare + password / require-login gates,
        | intersects with item-safety and stashes a ShareContext. A share grants
        | EXACTLY its bitmask on its node-set — the identity ACL is never consulted
        | here. An optional already-authenticated host user satisfies require_login.
        |
        | Rate-limited (P1.3 / F-P1.8-01) by the package's own `vaulter-public` named
        | limiter (config('vaulter.rate_limits.public'), keyed by requester IP) —
        | throttle runs BEFORE ResolveShareToken so an over-budget requester never
        | pays the token-hash + password-compare cost.
        */
        Route::middleware(['throttle:vaulter-public', ResolveShareToken::class])->group(static function (): void {
            Route::get('share/{token}', [PublicShareController::class, 'show']);
            Route::get('share/{token}/nodes', [PublicShareController::class, 'nodes']);
            Route::get('share/{token}/download', [PublicShareController::class, 'download']);
        });
    });
