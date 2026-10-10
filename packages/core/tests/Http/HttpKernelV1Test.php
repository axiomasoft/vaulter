<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Vaulter\Configuration\RoutesConfig;
use Vaulter\Http\Middleware\AddApiVersionHeader;
use Vaulter\Http\Middleware\ApplyResponseCachePolicy;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;
use Vaulter\Tests\Http\V1;

/**
 * The v1 kernel: one correlation id per request (kept when the caller sent a sane one),
 * the API version on every versioned response, the package middleware on the host's
 * stacks, and scalar route parameters with no model binding.
 */
uses(RefreshDatabase::class);

it('mounts the core routes under the versioned prefix with vaulter.v1 names and scalar parameters', function (): void {
    $route = app('router')->getRoutes()->getByName('vaulter.v1.grants.index');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/vaulter/v1/nodes/{node}/grants')
        ->and($route->wheres)->toMatchArray(['node' => '[A-Za-z0-9_-]+'])
        ->and($route->gatherMiddleware())->toContain(AssignCorrelationId::class, AddApiVersionHeader::class, ApplyResponseCachePolicy::class, RecordVaulterRequestMetrics::class)
        ->and($route->gatherMiddleware())->not->toContain('Illuminate\Routing\Middleware\SubstituteBindings');

    foreach (['grants', 'shares', 'activity'] as $legacy) {
        $this->getJson('api/vaulter/nodes/1/'.$legacy)->assertNotFound();
    }
    $this->getJson('api/vaulter/share/whatever')->assertNotFound();
});

it('keeps a well-formed X-Request-Id and echoes it with the api version', function (): void {
    $drive = V1::drive();

    $this->actingAs(V1::user())
        ->getJson(V1::url('nodes/'.$drive->root()->id.'/grants'), ['X-Request-Id' => 'trace-01:abc.DEF_9'])
        ->assertOk()
        ->assertHeader('X-Request-Id', 'trace-01:abc.DEF_9')
        ->assertHeader('X-Vaulter-Api-Version', '1');
});

it('replaces a malformed or oversized X-Request-Id with a fresh ULID, also on errors', function (string $incoming): void {
    $response = $this->actingAs(V1::user())
        ->getJson(V1::url('nodes/missing-node/grants'), ['X-Request-Id' => $incoming])
        ->assertNotFound()
        ->assertHeader('X-Vaulter-Api-Version', '1');

    $id = $response->headers->get('X-Request-Id');
    expect($id)->not->toBe($incoming)
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($response->json('request_id'))->toBe($id);
})->with([
    'header injection' => ["abc\r\nX-Evil: 1"],
    'too long' => [str_repeat('a', 65)],
    'space' => ['has space'],
]);

it('answers an unauthenticated call with 401 actor_required as problem+json', function (): void {
    $drive = V1::drive();

    $this->getJson(V1::url('nodes/'.$drive->root()->id.'/grants'))
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('X-Vaulter-Api-Version', '1')
        ->assertJsonPath('code', 'actor_required');
});

it('leaves a host route sharing the stack without Vaulter headers', function (): void {
    Route::middleware(app(RoutesConfig::class)->authenticatedMiddleware())->get('host/ping', static fn (): array => ['ok' => true]);

    $response = $this->getJson('host/ping')->assertOk();

    expect($response->headers->has('X-Vaulter-Api-Version'))->toBeFalse()
        ->and($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('rejects a per_page outside 1..200 with 422 validation_failed', function (int $perPage): void {
    $drive = V1::drive();

    $this->actingAs(V1::user())
        ->getJson(V1::url('drives/'.$drive->model()->getKey().'/activity?per_page='.$perPage))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
})->with([0, 201]);
