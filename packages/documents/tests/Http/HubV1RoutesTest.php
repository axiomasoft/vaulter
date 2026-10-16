<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Documents\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Documents\Tests\Http\DocumentsV1 as Docs;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Http\Middleware\VaulterHttpOperationMap;
use Vaulter\Http\Middleware\VaulterThrottledRouteMap;

/**
 * The hub's server-to-server calls on their own stack: a room JWT and a body signature
 * instead of a session, problem+json on every refusal, and the epoch fence's 409 stays
 * terminal. The protocol itself (load, seed, store, fences) is HubProtocolTest.
 */
beforeEach(function (): void {
    Hub::configure();
});

it('serves authenticate, load and store under the v1 hub names', function (): void {
    $node = Hub::document();
    $token = Hub::token($node);

    Hub::call('authenticate', ['room' => Hub::room($node)], $token)->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('mode', 'rw');
    Hub::call('load', ['room' => Hub::room($node)], $token)->assertOk()->assertJsonPath('type', 'vaulter/rich-text');
    Hub::call('store', Hub::storeBody($node, 'state'), $token)->assertOk()->assertJsonPath('data.changed', true);

    expect(collect(Router::getRoutes()->getRoutes())->map(fn (Route $route): ?string => $route->getName())->filter()->values()->all())
        ->toContain('vaulter.v1.collaboration.hub.authenticate', 'vaulter.v1.collaboration.hub.load', 'vaulter.v1.collaboration.hub.store');
});

it('refuses an unsigned or token-less call 401 as problem+json', function (string $endpoint): void {
    $node = Hub::document();
    $body = ['room' => Hub::room($node), 'state' => base64_encode('x')];

    $this->postJson(Docs::url('collaboration/hub/'.$endpoint), $body)
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'actor_required')
        ->assertJsonMissingPath('message');
    // A valid token without the body signature is no better.
    $this->postJson(Docs::url('collaboration/hub/'.$endpoint), $body, ['Authorization' => 'Bearer '.Hub::token($node)])->assertStatus(401);
})->with(['authenticate', 'load', 'store']);

it('refuses a store from a past generation with the terminal 409 and a read-only token with 403', function (): void {
    $node = Hub::document();
    $stale = Hub::token($node, epoch: 0);
    app(VaulterDatabase::class)->write(fn () => Hub::aggregate($node)->forceFill(['collab_epoch' => 1])->save());

    Hub::call('store', Hub::storeBody($node, 'late', epoch: 0), $stale)
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'collaboration_epoch_mismatch');

    $fresh = Hub::document();
    Hub::call('store', Hub::storeBody($fresh, 'ro'), Hub::token($fresh, mode: 'ro'))->assertForbidden()->assertJsonPath('code', 'permission_denied');
});

it('answers a malformed store body 422 validation_failed after the room was opened', function (): void {
    $node = Hub::document();

    Hub::call('store', ['room' => Hub::room($node)], Hub::token($node))->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('state');
    Hub::call('store', ['room' => Hub::room($node), 'state' => '***'], Hub::token($node))->assertStatus(422)->assertJsonValidationErrors('state');
    Hub::call('store', ['state_version' => -1] + Hub::storeBody($node, 'x'), Hub::token($node))->assertStatus(422)->assertJsonValidationErrors('state_version');
});

it('does not take a user session for a hub call, and the session routes are not on the hub stack', function (): void {
    $node = Hub::document();
    $this->actingAs(AccessTestHelpers::user(1));

    $this->postJson(Docs::url('collaboration/hub/authenticate'), ['room' => Hub::room($node)])->assertStatus(401);

    $hub = Router::getRoutes()->getByName('vaulter.v1.collaboration.hub.store');
    $content = Router::getRoutes()->getByName('vaulter.v1.documents.content.update');
    expect($hub->middleware())->toContain('throttle:vaulter-public')
        ->and($hub->gatherMiddleware())->toContain(ResolveCollaborationCall::class)
        ->and($content->gatherMiddleware())->not->toContain(ResolveCollaborationCall::class)
        ->and($content->gatherMiddleware())->not->toContain('throttle:vaulter-public');
});

it('rate-limits the hub like the other public surface and names it for the metrics', function (): void {
    expect(VaulterThrottledRouteMap::routeFor('vaulter.v1.collaboration.hub.store'))->toBe('collab.store')
        ->and(VaulterThrottledRouteMap::routeFor('vaulter.v1.collaboration.hub.authenticate'))->toBe('collab.authenticate')
        ->and(VaulterHttpOperationMap::operationFor('vaulter.v1.collaboration.hub.store'))->toBe('collab_persist');
});
