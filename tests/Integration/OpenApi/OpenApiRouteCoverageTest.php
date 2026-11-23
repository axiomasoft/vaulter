<?php

declare(strict_types=1);

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Vaulter\Configuration\RoutesOptions;
use Vaulter\Integration\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Repo\Tests\Support\ReleaseOpenApi;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;

require_once dirname(__DIR__, 2).'/Repo/Arch/Support/ReleaseOpenApi.php';

// The contract describes the webhooks routes and problems too: the opt-in module is on.
uses(EnablesWebhooksModule::class);

/**
 * The route table and the contract describe the same API: no route without an operation, no
 * operation without a route, and the same method and path on both sides.
 */

/** @return array<string, LaravelRoute> route name without the version prefix => route */
function v1Routes(): array
{
    return ReleaseOpenApi::routes(Route::getRoutes()->getRoutes(), 'public');
}

it('names an operation for every vaulter.v1 route and a route for every operation', function (): void {
    $operations = array_keys(OpenApiContract::shared()->operations());
    sort($operations);

    expect(array_keys(v1Routes()))->toBe($operations);
});

it('agrees with every route on method and path', function (): void {
    $prefix = '/'.app(RoutesOptions::class)->versionedPrefix();
    $operations = OpenApiContract::shared()->operations();

    foreach (v1Routes() as $name => $route) {
        $methods = array_values(array_diff($route->methods(), ['HEAD'])) ?: ['HEAD'];

        expect($methods)->toBe([$operations[$name]['method']], "{$name}: HTTP method")
            ->and('/'.ltrim($route->uri(), '/'))->toBe($prefix.$operations[$name]['path'], "{$name}: path");
    }
});

it('mounts the contract under the documented server url', function (): void {
    $server = OpenApiContract::shared()->document->servers[0]->url;

    expect($server)->toBe('/'.app(RoutesOptions::class)->versionedPrefix());
});

it('assigns every registered endpoint to exactly its owner spec with closed references', function (): void {
    ReleaseOpenApi::assertContract(OpenApiContract::shared(), v1Routes());
    expect(OpenApiContract::shared()->operation('documents.collaboration.session'))->not->toBeNull();
});

it('rejects public endpoint loss orphan references and missing problem metadata', function (): void {
    $source = Yaml::parseFile(OpenApiContract::specPath());
    $missing = $source;
    unset($missing['paths'][array_key_first($missing['paths'])]);
    $orphan = $source;
    $orphan['components']['schemas']['Problem']['properties']['code']['$ref'] = '#/components/schemas/UnownedProblemCode';
    $metadata = $source;
    unset($metadata['components']['schemas']['Problem']['x-vaulter-problems']);
    foreach ([$missing, $orphan, $metadata] as $fixture) {
        expect(fn () => ReleaseOpenApi::assertContract(OpenApiContract::fromYaml(Yaml::dump($fixture, 30)), v1Routes()))->toThrow(LogicException::class);
    }
});
