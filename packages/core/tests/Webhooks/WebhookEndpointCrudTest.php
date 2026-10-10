<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookSecret;

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

it('registers an endpoint and returns the secret exactly once', function (): void {
    $response = $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created', 'share.created'],
    ])->assertCreated();

    $secret = $response->json('data.secret');

    expect($secret)->toBeString()
        ->and($secret)->toStartWith('whsec_')
        ->and(strlen(WebhookSecret::keyBytes((string) $secret)))->toBe(32)
        ->and($response->json('data.url'))->toBe('https://hooks.test/receive')
        ->and($response->json('data.events'))->toBe(['folder.created', 'share.created'])
        ->and($response->json('data.status'))->toBe('active')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    // The stored secret matches what was returned — and it is NEVER re-serialized.
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($endpoint->signing_secret)->toBe($secret);

    $list = $this->getJson('api/vaulter/v1/webhook-endpoints')->assertOk();

    expect($list->json('data.0'))->not->toHaveKey('secret')
        ->and(array_keys($list->json('data.0')))->toBe(['id', 'url', 'drive_id', 'events', 'status', 'disabled_at', 'created_at'])
        ->and($list->json('meta'))->toBe(['next_cursor' => null, 'per_page' => 50]);
});

it('rejects a registration with an unknown event name', function (): void {
    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['totally.bogus'],
    ])->assertStatus(422);
});

it('rejects a registration with no events', function (): void {
    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => [],
    ])->assertStatus(422);
});

// ─────────────── list / update / deactivate / delete ───────────────

it('lists registered endpoints without ever exposing the secret', function (): void {
    WebhookTestHelpers::endpoint(['url' => 'https://a.test/hook']);
    WebhookTestHelpers::endpoint(['url' => 'https://b.test/hook']);

    $response = $this->getJson('api/vaulter/v1/webhook-endpoints')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0'))->not->toHaveKey('secret');
});

it('updates an endpoint url, events and active flag (partial)', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['events' => ['folder.created']]);

    $this->patchJson('api/vaulter/v1/webhook-endpoints/'.$endpoint->getKey(), [
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

    $this->postJson('api/vaulter/v1/webhook-endpoints/'.$endpoint->getKey().'/deactivate')->assertOk();

    $endpoint->refresh();

    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->failure_count)->toBe(0);
});

it('deletes an endpoint', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();

    $this->deleteJson('api/vaulter/v1/webhook-endpoints/'.$endpoint->getKey())->assertNoContent();

    expect(WebhookEndpoint::query()->whereKey($endpoint->getKey())->exists())->toBeFalse();
});

it('404s for an unknown endpoint id', function (): void {
    $this->patchJson('api/vaulter/v1/webhook-endpoints/999999999', [
        'is_active' => false,
    ])->assertNotFound()->assertJsonPath('code', 'webhook_endpoint_not_found');
});

// ─────────────── authorization ───────────────

it('forbids management when the configured manage ability denies', function (): void {
    // Define a host ability and DENY it: the policy must 403 every management action.
    config()->set('vaulter.webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', fn (): bool => false);

    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created'],
    ])->assertForbidden();

    $this->getJson('api/vaulter/v1/webhook-endpoints')->assertForbidden();
});

it('allows management when the configured manage ability grants', function (): void {
    config()->set('vaulter.webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', fn (): bool => true);

    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://hooks.test/receive',
        'events' => ['folder.created'],
    ])->assertCreated();
});
