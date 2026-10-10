<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Illuminate\Http\Request;
use Vaulter\Configuration\RoutesConfig;

/**
 * §9 logical `route` ids for `vaulter_throttled_total` (throttle:vaulter-public / uploads).
 *
 * Versioned routes are resolved by route name; the path rules serve only the unversioned
 * documents routes until that package moves to v1.
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
    ];

    public static function routeFor(string $routeName): ?string
    {
        return self::NAMED[$routeName] ?? null;
    }

    /**
     * @return list<array{methods: list<string>, pattern: string, route: string}>
     */
    private static function rules(): array
    {
        return [
            ['methods' => ['POST'], 'pattern' => 'collab/authenticate', 'route' => 'collab.authenticate'],
            ['methods' => ['POST'], 'pattern' => 'collab/store', 'route' => 'collab.store'],
        ];
    }

    public static function resolve(Request $request): ?string
    {
        $name = $request->route()?->getName();
        if (is_string($name) && str_starts_with($name, 'vaulter.v1.')) {
            return self::routeFor($name);
        }

        $prefix = trim(app(RoutesConfig::class)->prefix(), '/');
        $path = trim($request->path(), '/');

        if ($prefix !== '' && str_starts_with($path, $prefix)) {
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }

        $method = strtoupper($request->method());
        $normalized = trim($path, '/');

        foreach (self::rules() as $rule) {
            if (!in_array($method, $rule['methods'], true)) {
                continue;
            }

            $regex = '#^'.preg_replace('#\{id\}#', '[^/]+', $rule['pattern']).'$#';

            if (preg_match($regex, $normalized) === 1) {
                return $rule['route'];
            }
        }

        return null;
    }
}
