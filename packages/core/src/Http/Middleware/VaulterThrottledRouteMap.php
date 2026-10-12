<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Illuminate\Http\Request;

/**
 * logical `route` ids for `vaulter_throttled_total` (throttle:vaulter-public / uploads).
 *
 * Routes are resolved by their name.
 */
final class VaulterThrottledRouteMap
{
    /** @var array<string, string> route name => logical route id */
    private const array NAMED = [
        'vaulter.v1.public-shares.show' => 'share.show',
        'vaulter.v1.public-shares.nodes' => 'share.nodes',
        'vaulter.v1.public-shares.download' => 'share.download',
        'vaulter.v1.uploads.store' => 'uploads.tus_create',
        'vaulter.v1.uploads.tus.patch' => 'uploads.tus_patch',
        'vaulter.v1.uploads.show' => 'uploads.tus_status',
        'vaulter.v1.uploads.tus.head' => 'uploads.tus_status',
        'vaulter.v1.uploads.parts.store' => 'uploads.s3_parts',
        'vaulter.v1.uploads.complete' => 'uploads.s3_complete',
        'vaulter.v1.files.store' => 'uploads.single',
        'vaulter.v1.collaboration.hub.authenticate' => 'collab.authenticate',
        'vaulter.v1.collaboration.hub.store' => 'collab.store',
    ];

    public static function routeFor(string $routeName): ?string
    {
        return self::NAMED[$routeName] ?? null;
    }

    public static function resolve(Request $request): ?string
    {
        $name = $request->route()?->getName();

        return is_string($name) ? self::routeFor($name) : null;
    }
}
