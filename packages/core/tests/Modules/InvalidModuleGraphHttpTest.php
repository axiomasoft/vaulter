<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Http\Controllers\ModuleGraphInvalidController;
use Vaulter\Kernel;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\TestCase;

uses(TestCase::class, WithoutDatabaseSchema::class);

// Revision 3: with an invalid module graph no module mounts a route; one catch-all under the Vaulter
// prefix answers 503 module_graph_invalid. Routes outside the prefix are the host's.

it('answers every route under the Vaulter prefix 503 module_graph_invalid', function (string $method, string $uri): void {
    $app = FreshApplication::boot(['vaulter.modules' => ['nope' => true]]);
    $response = $app->make(HttpKernel::class)->handle(Request::create($uri, $method, server: ['HTTP_ACCEPT' => 'application/json']));

    expect($response->getStatusCode())->toBe(503)
        ->and(json_decode((string) $response->getContent(), true))->toMatchArray(['code' => 'module_graph_invalid', 'status' => 503]);
})->with([
    'a drive route' => ['GET', '/api/vaulter/v1/nodes/n1/grants'],
    'a webhooks route' => ['POST', '/api/vaulter/v1/webhook-endpoints'],
    'an unknown route' => ['GET', '/api/vaulter/v1/whatever/else'],
]);

it('leaves the routes outside the Vaulter prefix to the host', function (): void {
    $app = FreshApplication::boot(['vaulter.modules' => ['nope' => true]]);

    expect($app->make(HttpKernel::class)->handle(Request::create('/elsewhere'))->getStatusCode())->toBe(404);
});

it('registers no catch-all under an empty prefix, which would swallow the whole host', function (): void {
    $app = FreshApplication::boot(['vaulter.modules' => ['nope' => true], 'vaulter.routes.prefix' => '']);

    expect($app->make(HttpKernel::class)->handle(Request::create('/elsewhere'))->getStatusCode())->toBe(404);
});

it('answers 404, not 503, from a catch-all left in a route cache once the graph is valid', function (): void {
    $app = FreshApplication::boot();

    expect(fn () => $app->make(ModuleGraphInvalidController::class)($app->make(Kernel::class)))
        ->toThrow(NotFoundHttpException::class);
});
