<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Tests\Http\V1;

/**
 * There is one HTTP surface: versioned, named and answered as problem+json. Nothing is
 * left under the configured prefix without a `vaulter.v1.*` name, the old unversioned
 * paths are plain 404s, and the exceptions no longer render themselves.
 */
uses(RefreshDatabase::class);

it('registers no route under the prefix without a vaulter.v1.* name', function (): void {
    $prefix = trim(app(RoutesConfig::class)->prefix(), '/');
    $routes = array_filter(
        iterator_to_array(app('router')->getRoutes()),
        static fn (Route $route): bool => $route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/'),
    );

    expect($routes)->not->toBeEmpty();
    foreach ($routes as $route) {
        expect($route->getName())->toStartWith('vaulter.v1.', $route->uri())
            ->and($route->uri())->toStartWith($prefix.'/v1/');
    }
});

it('answers the old unversioned paths 404 route_not_found, never 200', function (string $method, string $path): void {
    $this->actingAs(V1::user(1));

    $this->json($method, 'api/vaulter/'.$path)->assertNotFound();
})->with([
    ['GET', 'nodes/1'],
    ['GET', 'nodes/1/content'],
    ['PATCH', 'nodes/1/content'],
    ['GET', 'nodes/1/document-versions'],
    ['GET', 'nodes/1/export'],
    ['POST', 'nodes/1/collab/token'],
    ['GET', 'storages/1'],
    ['GET', 'storages/1/nodes'],
    ['POST', 'storages/1/documents'],
    ['GET', 'share/token'],
]);

it('answers a path under the versioned prefix that names no route as problem+json route_not_found', function (): void {
    $this->actingAs(V1::user(1));

    $this->getJson(V1::url('collab/store'))
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'route_not_found');
    $this->postJson(V1::url('collab/authenticate'))->assertNotFound()->assertJsonPath('code', 'route_not_found');
});

it('leaves VaulterException without a render method of its own', function (): void {
    expect(method_exists(VaulterException::class, 'render'))->toBeFalse()
        ->and(class_exists('Vaulter\Http\Middleware\ResolveVaulterRouteParameters'))->toBeFalse();
});
