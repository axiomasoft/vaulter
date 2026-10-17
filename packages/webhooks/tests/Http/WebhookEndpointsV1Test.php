<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * The webhook admin surface over v1: global, gated by the manage ability, secrets shown
 * only by register and rotate-secret, deliveries paged per endpoint and re-sent on
 * request.
 */

/** Fixture rows are written the way the operations write them, so the write guard accepts them. */
function webhookFixture(Closure $create): mixed
{
    return app(VaulterDatabase::class)->write($create);
}

beforeEach(function (): void {
    config()->set('vaulter-webhooks.manage_ability', null);
    $this->actingAs(V1::user(1));
});

it('shows an endpoint without its secret and 404s an unknown one', function (): void {
    $endpoint = webhookFixture(static fn () => WebhookTestHelpers::endpoint(['url' => 'https://show.test/hook']));

    $this->getJson(V1::url('webhook-endpoints/'.$endpoint->getKey()))
        ->assertOk()
        ->assertJsonPath('data.id', (string) $endpoint->getKey())
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissingPath('data.secret');
    $this->getJson(V1::url('webhook-endpoints/987654'))->assertNotFound()->assertJsonPath('code', 'webhook_endpoint_not_found');
});

it('rotates the signing secret and returns the new one once', function (): void {
    $endpoint = webhookFixture(static fn () => WebhookTestHelpers::endpoint());
    $old = $endpoint->signing_secret;

    $rotated = $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/rotate-secret'), ['overlap_seconds' => 600])
        ->assertOk()
        ->assertJsonPath('data.id', (string) $endpoint->getKey());

    expect($rotated->json('data.secret'))->toStartWith('whsec_')->not->toBe($old)
        ->and($endpoint->fresh()->signing_secret)->toBe($rotated->json('data.secret'))
        ->and($endpoint->fresh()->previous_signing_secret)->toBe($old)
        ->and($rotated->headers->get('Cache-Control'))->toContain('no-store');
    $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/rotate-secret'), ['overlap_seconds' => -1])->assertUnprocessable();
});

it('pages the deliveries of one endpoint only', function (): void {
    $endpoint = webhookFixture(static fn () => WebhookTestHelpers::endpoint());
    $other = webhookFixture(static fn () => WebhookTestHelpers::endpoint(['url' => 'https://other.test/hook']));
    foreach (range(1, 3) as $i) {
        webhookFixture(static fn () => WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'last_error' => 'HTTP 503', 'attempts' => $i]));
    }
    webhookFixture(static fn () => WebhookTestHelpers::delivery($other));

    $page = $this->getJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries?per_page=2'))->assertOk();

    expect($page->json('data'))->toHaveCount(2)
        ->and($page->json('meta.next_cursor'))->toBeString()
        ->and(array_keys($page->json('data.0')))->toBe(['id', 'event_id', 'event_type', 'status', 'attempts', 'next_retry_at', 'last_status_code', 'created_at'])
        ->and($page->json('data.0.last_status_code'))->toBe(503)
        ->and($page->json('data.0.event_type'))->toBe('folder.created');
    $rest = $this->getJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries?per_page=2&cursor='.urlencode($page->json('meta.next_cursor'))))->assertOk();
    expect($rest->json('data'))->toHaveCount(1);
});

it('redelivers a dead delivery (202), refuses a pending one (409) and one of another endpoint (404)', function (): void {
    Bus::fake([DeliverWebhook::class]);
    $endpoint = webhookFixture(static fn () => WebhookTestHelpers::endpoint());
    $other = webhookFixture(static fn () => WebhookTestHelpers::endpoint(['url' => 'https://other.test/hook']));
    $dead = webhookFixture(static fn () => WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'last_error' => 'connection refused']));
    $pending = webhookFixture(static fn () => WebhookTestHelpers::delivery($endpoint));
    $foreign = webhookFixture(static fn () => WebhookTestHelpers::delivery($other, ['status' => 'dead']));
    $base = V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries/');

    $this->postJson($base.$dead->getKey().'/redeliver')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.last_status_code', null);
    $this->postJson($base.$pending->getKey().'/redeliver')->assertStatus(409)->assertJsonPath('code', 'webhook_delivery_not_redeliverable');
    $this->postJson($base.$foreign->getKey().'/redeliver')->assertNotFound()->assertJsonPath('code', 'webhook_delivery_not_found');
    expect($foreign->fresh()->status)->toBe('dead');
});

it('gates every action, reads included, behind the manage ability', function (): void {
    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', static fn (): bool => false);
    $endpoint = webhookFixture(static fn () => WebhookTestHelpers::endpoint());
    $delivery = webhookFixture(static fn () => WebhookTestHelpers::delivery($endpoint, ['status' => 'dead']));
    $id = $endpoint->getKey();

    foreach ([
        ['GET', 'webhook-endpoints/'.$id],
        ['GET', 'webhook-endpoints/'.$id.'/deliveries'],
        ['POST', 'webhook-endpoints/'.$id.'/rotate-secret'],
        ['POST', 'webhook-endpoints/'.$id.'/deactivate'],
        ['POST', 'webhook-endpoints/'.$id.'/deliveries/'.$delivery->getKey().'/redeliver'],
        ['DELETE', 'webhook-endpoints/'.$id],
        // Existence is not revealed to a caller who may not manage.
        ['GET', 'webhook-endpoints/987654'],
    ] as [$method, $path]) {
        $this->json($method, V1::url($path))->assertForbidden()->assertJsonPath('code', 'permission_denied');
    }
});
