<?php

declare(strict_types=1);

namespace Vaulter\Drive\Tests\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Http\Support\Preconditions;

/**
 * A test-only node read and rename on the real authenticated v1 stack and the real
 * NodeOperations, in the exact order the drive routes follow: resolve, find (which
 * authorizes), build the caller's representation, then compare validators, then
 * mutate with the expected sequence. It proves 304/428/412 before the drive routes
 * exist.
 */
final class NodeProbeRoutes
{
    public const string SHOW = 'vaulter.v1.__probe.node.show';

    public const string UPDATE = 'vaulter.v1.__probe.node.update';

    public static function register(): void
    {
        $routes = app(RoutesConfig::class);

        Route::prefix($routes->versionedPrefix())
            ->middleware($routes->authenticatedMiddleware())
            ->where(['node' => '[A-Za-z0-9_-]+'])
            ->group(static function (): void {
                Route::get('__probe/nodes/{node}', static function (Request $request, string $node): JsonResponse|Response {
                    $drive = app(HttpOperationContextFactory::class)->forDrive($request, app(HttpResourceResolver::class)->driveOfNode($node));
                    $snapshot = $drive->nodes()->find($node);
                    $resource = new NodeResource($snapshot, $drive->nodes()->abilities($snapshot));
                    if (Preconditions::notModified($request, $resource->etag())) {
                        return (new Response(status: 304))->setEtag($resource->etag());
                    }

                    return $resource->response()->setEtag($resource->etag());
                })->name(self::SHOW);

                Route::patch('__probe/nodes/{node}', static function (Request $request, string $node): JsonResponse {
                    $drive = app(HttpOperationContextFactory::class)->forDrive($request, app(HttpResourceResolver::class)->driveOfNode($node));
                    $snapshot = $drive->nodes()->find($node);
                    $current = new NodeResource($snapshot, $drive->nodes()->abilities($snapshot));
                    $expected = Preconditions::matchNode($request, $current->etag(), $snapshot);
                    $renamed = $drive->nodes()->rename($node, $request->string('name')->toString(), $expected);
                    $resource = new NodeResource($renamed, $drive->nodes()->abilities($renamed));

                    return $resource->response()->setEtag($resource->etag());
                })->name(self::UPDATE);
            });
        app('router')->getRoutes()->refreshNameLookups();
    }

    public static function path(string $node): string
    {
        return app(RoutesConfig::class)->versionedPrefix().'/__probe/nodes/'.$node;
    }
}
