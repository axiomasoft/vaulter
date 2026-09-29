<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Illuminate\Http\Request;
use Vaulter\Support\Config;

/**
 * §9 logical `route` ids for `vaulter_throttled_total` (throttle:vaulter-public / uploads).
 */
final class VaulterThrottledRouteMap
{
    /**
     * @return list<array{methods: list<string>, pattern: string, route: string}>
     */
    private static function rules(): array
    {
        return [
            ['methods' => ['GET'], 'pattern' => 'share/{id}', 'route' => 'share.show'],
            ['methods' => ['GET'], 'pattern' => 'share/{id}/nodes', 'route' => 'share.nodes'],
            ['methods' => ['GET'], 'pattern' => 'share/{id}/download', 'route' => 'share.download'],
            ['methods' => ['POST'], 'pattern' => 'collab/authenticate', 'route' => 'collab.authenticate'],
            ['methods' => ['POST'], 'pattern' => 'collab/store', 'route' => 'collab.store'],
            ['methods' => ['POST'], 'pattern' => 'storages/{id}/resumable-uploads', 'route' => 'uploads.tus_create'],
            ['methods' => ['PATCH'], 'pattern' => 'resumable-uploads/{id}', 'route' => 'uploads.tus_patch'],
            ['methods' => ['GET', 'HEAD'], 'pattern' => 'resumable-uploads/{id}', 'route' => 'uploads.tus_status'],
            ['methods' => ['POST'], 'pattern' => 'resumable-uploads/{id}/parts', 'route' => 'uploads.s3_parts'],
            ['methods' => ['POST'], 'pattern' => 'resumable-uploads/{id}/complete', 'route' => 'uploads.s3_complete'],
            ['methods' => ['POST'], 'pattern' => 'storages/{id}/uploads', 'route' => 'uploads.single'],
        ];
    }

    public static function resolve(Request $request): ?string
    {
        $prefix = trim(Config::routePrefix(), '/');
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
