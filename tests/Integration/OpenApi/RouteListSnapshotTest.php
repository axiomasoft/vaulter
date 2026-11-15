<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Integration\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Testing\Support\OpenApi\ValidateOpenApiResponses;

// The contract describes the webhooks routes and problems too: the opt-in module is on.
uses(EnablesWebhooksModule::class);

/**
 * The route table of v1 as a golden file: a route added, renamed, re-pathed or moved to
 * another middleware stack shows up in review next to the OpenAPI change it needs.
 * Regenerate with UPDATE_GOLDEN=1 and commit the diff.
 */
function routeSnapshot(): array
{
    $snapshot = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();
        if (!str_starts_with($name, ValidateOpenApiResponses::PREFIX) || ValidateOpenApiResponses::isProbe($name)) {
            continue;
        }

        $methods = $route->methods();
        sort($methods);
        $snapshot[$name] = [
            'name' => $name,
            'methods' => $methods,
            'uri' => $route->uri(),
            'middleware' => $route->gatherMiddleware(),
        ];
    }
    ksort($snapshot);

    return array_values($snapshot);
}

it('matches the golden route list', function (): void {
    $path = __DIR__.'/../../../packages/core/tests/Fixtures/golden/routes-v1.json';
    $actual = json_encode(routeSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

    if (getenv('UPDATE_GOLDEN') === '1') {
        file_put_contents($path, $actual);
    }

    expect($actual)->toBe((string) file_get_contents($path));
});
