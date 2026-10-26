<?php

declare(strict_types=1);

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Integration\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;
use Vaulter\Testing\Support\OpenApi\ValidateOpenApiResponses;

// The contract describes the webhooks routes and problems too: the opt-in module is on (D22).
uses(EnablesWebhooksModule::class);

/**
 * The route table and the contract describe the same API: no route without an operation, no
 * operation without a route, and the same method and path on both sides.
 */

/** @return array<string, LaravelRoute> route name without the version prefix => route */
function v1Routes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (str_starts_with($name, ValidateOpenApiResponses::PREFIX) && !ValidateOpenApiResponses::isProbe($name)) {
            $routes[substr($name, strlen(ValidateOpenApiResponses::PREFIX))] = $route;
        }
    }
    ksort($routes);

    return $routes;
}

it('names an operation for every vaulter.v1 route and a route for every operation', function (): void {
    $operations = array_keys(OpenApiContract::shared()->operations());
    sort($operations);

    expect(array_keys(v1Routes()))->toBe($operations);
});

it('agrees with every route on method and path', function (): void {
    $prefix = '/'.app(RoutesConfig::class)->versionedPrefix();
    $operations = OpenApiContract::shared()->operations();

    foreach (v1Routes() as $name => $route) {
        $methods = array_values(array_diff($route->methods(), ['HEAD'])) ?: ['HEAD'];

        expect($methods)->toBe([$operations[$name]['method']], "{$name}: HTTP method")
            ->and('/'.ltrim($route->uri(), '/'))->toBe($prefix.$operations[$name]['path'], "{$name}: path");
    }
});

it('mounts the contract under the documented server url', function (): void {
    $server = OpenApiContract::shared()->document->servers[0]->url;

    expect($server)->toBe('/'.app(RoutesConfig::class)->versionedPrefix());
});
