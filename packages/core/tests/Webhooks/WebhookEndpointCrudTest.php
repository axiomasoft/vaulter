<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

/**
 * The webhook-endpoint management HTTP surface (§2.11 / D14 / M3b): register / list /
 * update / deactivate / delete. The signing_secret is shown EXACTLY ONCE at
 * registration and never re-serialized. Every action is gated by the manage ability;
 * with no host ability defined, an authenticated user is allowed (the base suite).
 */
beforeEach(function (): void {
    // No host manage ability defined in the base suite → an authenticated user passes
    // the policy (the route still requires auth).
    config()->set('vaulter.webhooks.manage_ability', null);
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
});

// ─────────────── register — secret shown once ───────────────

it('registers an endpoint and returns the signing_secret exactly once', function (): void {
    $response = $this->postJson('api/vaulter/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['node.created', 'share.created'],
    ])->assertCreated();

    $secret = $response->json('data.signing_secret');

    expect($secret)->toBeString()
        ->and(strlen((string) $secret))->toBe(64)
        ->and($response->json('data.url'))->toBe('https://hooks.test/receive')
        ->and($response->json('data.events'))->toBe(['node.created', 'share.created'])
        ->and($response->json('data.is_active'))->toBeTrue();

    // The stored secret matches what was returned — and it is NEVER re-serialized.
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($endpoint->signing_secret)->toBe($secret);

    $list = $this->getJson('api/vaulter/webhook-endpoints')->assertOk();

    expect($list->json('data.0'))->not->toHaveKey('signing_secret');
});

it('rejects a registration with an unknown event name', function (): void {
    $this->postJson('api/vaulter/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['totally.bogus'],
    ])->assertStatus(422);
});

it('rejects a registration with no events', function (): void {
    $this->postJson('api/vaulter/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => [],
    ])->assertStatus(422);
});

// ─────────────── list / update / deactivate / delete ───────────────

it('lists registered endpoints without ever exposing the secret', function (): void {
    WebhookTestHelpers::endpoint(['url' => 'https://a.test/hook']);
    WebhookTestHelpers::endpoint(['url' => 'https://b.test/hook']);

    $response = $this->getJson('api/vaulter/webhook-endpoints')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0'))->not->toHaveKey('signing_secret');
});

it('updates an endpoint url, events and active flag (partial)', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['events' => ['node.created']]);

    $this->patchJson('api/vaulter/webhook-endpoints/'.$endpoint->getKey(), [
        'events' => ['node.moved', 'node.trashed'],
        'is_active' => false,
    ])->assertOk();

    $endpoint->refresh();

    expect($endpoint->events)->toBe(['node.moved', 'node.trashed'])
        ->and($endpoint->is_active)->toBeFalse()
        // The url was not in the PATCH — left unchanged.
        ->and($endpoint->url)->toBe('https://hooks.test/receive');
});

it('deactivates an endpoint and resets its failure_count', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['is_active' => true, 'failure_count' => 4]);

    $this->postJson('api/vaulter/webhook-endpoints/'.$endpoint->getKey().'/deactivate')->assertOk();

    $endpoint->refresh();

    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->failure_count)->toBe(0);
});

it('deletes an endpoint', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    $this->deleteJson('api/vaulter/webhook-endpoints/'.$endpoint->getKey())->assertNoContent();

    expect(WebhookEndpoint::query()->whereKey($endpoint->getKey())->exists())->toBeFalse();
});

it('404s for an unknown endpoint id', function (): void {
    $this->patchJson('api/vaulter/webhook-endpoints/does-not-exist', [
        'is_active' => false,
    ])->assertNotFound();
});

// ─────────────── authorization ───────────────

it('forbids management when the configured manage ability denies', function (): void {
    // Define a host ability and DENY it: the policy must 403 every management action.
    config()->set('vaulter.webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', fn (): bool => false);

    $this->postJson('api/vaulter/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['node.created'],
    ])->assertForbidden();

    $this->getJson('api/vaulter/webhook-endpoints')->assertForbidden();
});

it('allows management when the configured manage ability grants', function (): void {
    config()->set('vaulter.webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', fn (): bool => true);

    $this->postJson('api/vaulter/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['node.created'],
    ])->assertCreated();
});
