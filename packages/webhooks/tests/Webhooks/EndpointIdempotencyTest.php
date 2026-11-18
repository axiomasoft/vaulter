<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Idempotency\ResourceRef;
use Vaulter\Testing\Tenancy\MutableTenantResolver;
use Vaulter\Webhooks\Exceptions\WebhookEndpointNotFoundException;
use Vaulter\Webhooks\Idempotency\EndpointDeleteReceipt;
use Vaulter\Webhooks\Idempotency\EndpointReplayAuthorizer;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Repositories\WebhookEndpointReadRepository;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * The endpoint writes are receipt operations. A retry under the same Idempotency-Key
 * returns what the first request answered, even once its If-Match went stale because that request committed, and changes
 * nothing; the signing secret of a registration or a rotation sits in the receipt only as ciphertext; a replay is
 * authorized again by the `manage` ability. A redelivery is a receipt operation too: a keyed retry sends nothing again.
 */
beforeEach(function (): void {
    config()->set('vaulter-webhooks.manage_ability');
    $this->actingAs(V1::user(1));
});

/** @return list<string> the stored receipt bodies */
function endpointReceiptBodies(): array
{
    return array_values(array_map(static fn (mixed $body): string => (string) $body, app(VaulterDatabase::class)->connection()->table(app(IdempotencyStore::class)->table())->pluck('receipt_body')->all()));
}

function endpointFixture(array $attributes = []): WebhookEndpoint
{
    return app(VaulterDatabase::class)->write(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint($attributes));
}

it('registers once per key and replays the same secret from a receipt that holds only its ciphertext', function (): void {
    $body = ['url' => 'https://idem.test/hook', 'events' => ['folder.created']];

    $first = $this->postJson(V1::url('webhook-endpoints'), $body, ['Idempotency-Key' => 'reg-1'])->assertCreated();
    $again = $this->postJson(V1::url('webhook-endpoints'), $body, ['Idempotency-Key' => 'reg-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    $secret = $first->json('data.secret');
    expect($secret)->toStartWith('whsec_')
        ->and($again->json('data'))->toBe($first->json('data'))
        ->and($again->headers->get('ETag'))->toBe($first->headers->get('ETag'))
        ->and(WebhookEndpoint::query()->count())->toBe(1);

    // ID5: the plaintext secret is nowhere in the receipt; only the application's ciphertext of it is.
    $bodies = endpointReceiptBodies();
    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->not->toContain($secret)
        ->and($bodies[0])->not->toContain(substr((string) $secret, 6));
    $stored = json_decode($bodies[0], true, flags: JSON_THROW_ON_ERROR);
    expect(Crypt::decryptString($stored['secret']))->toBe($secret);
});

it('refuses the same key for another registration', function (): void {
    $this->postJson(V1::url('webhook-endpoints'), ['url' => 'https://a.test/hook', 'events' => ['folder.created']], ['Idempotency-Key' => 'reg-2'])->assertCreated();

    $this->postJson(V1::url('webhook-endpoints'), ['url' => 'https://b.test/hook', 'events' => ['folder.created']], ['Idempotency-Key' => 'reg-2'])
        ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    expect(WebhookEndpoint::query()->count())->toBe(1);
});

it('refuses to replay a registration to a caller who may no longer manage endpoints, and keeps the secret out of the answer', function (): void {
    $body = ['url' => 'https://idem.test/hook', 'events' => ['folder.created']];
    $first = $this->postJson(V1::url('webhook-endpoints'), $body, ['Idempotency-Key' => 'reg-3'])->assertCreated();

    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', static fn (): bool => false);

    $denied = $this->postJson(V1::url('webhook-endpoints'), $body, ['Idempotency-Key' => 'reg-3'])->assertForbidden();
    expect($denied->getContent())->not->toContain($first->json('data.secret'));
});

it('refuses to replay a registration once the endpoint is gone, not its old secret', function (): void {
    $body = ['url' => 'https://idem.test/hook', 'events' => ['folder.created']];
    $first = $this->postJson(V1::url('webhook-endpoints'), $body, ['Idempotency-Key' => 'reg-4'])->assertCreated();
    $id = $first->json('data.id');
    $this->deleteJson(V1::url('webhook-endpoints/'.$id), [], ['If-Match' => $first->headers->get('ETag')])->assertNoContent();

    $answer = $this->postJson(V1::url('webhook-endpoints'), $body, ['Idempotency-Key' => 'reg-4'])->assertNotFound();
    expect($answer->getContent())->not->toContain($first->json('data.secret'));
});

it('replays an update whose If-Match went stale because the first request committed, without a second change', function (): void {
    $endpoint = endpointFixture();
    $headers = ['If-Match' => webhookTag($endpoint->getKey()), 'Idempotency-Key' => 'upd-1'];

    $first = $this->patchJson(V1::url('webhook-endpoints/'.$endpoint->getKey()), ['url' => 'https://new.test/hook'], $headers)->assertOk();
    $again = $this->patchJson(V1::url('webhook-endpoints/'.$endpoint->getKey()), ['url' => 'https://new.test/hook'], $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data'))->toBe($first->json('data'))
        ->and($first->json('data.version'))->toBe(1)
        ->and($endpoint->fresh()->version)->toBe(1);
    // The same stale If-Match without the key is the 412 it always was.
    $this->patchJson(V1::url('webhook-endpoints/'.$endpoint->getKey()), ['url' => 'https://new.test/hook'], ['If-Match' => $headers['If-Match']])
        ->assertStatus(412)->assertJsonPath('code', 'stale_write');
});

it('answers a stale If-Match with 412 stale_write and changes nothing, under a key too', function (): void {
    $endpoint = endpointFixture();
    $stale = webhookTag($endpoint->getKey(), 7);

    $first = $this->patchJson(V1::url('webhook-endpoints/'.$endpoint->getKey()), ['url' => 'https://new.test/hook'], ['If-Match' => $stale, 'Idempotency-Key' => 'upd-2'])
        ->assertStatus(412)->assertJsonPath('code', 'stale_write');
    $this->patchJson(V1::url('webhook-endpoints/'.$endpoint->getKey()), ['url' => 'https://new.test/hook'], ['If-Match' => $stale, 'Idempotency-Key' => 'upd-2'])
        ->assertStatus(412)->assertJsonPath('code', 'stale_write');

    expect($first->headers->get('Content-Type'))->toContain('problem+json')
        ->and($endpoint->fresh()->url)->toBe('https://hooks.test/receive')
        ->and($endpoint->fresh()->version)->toBe(0);
});

it('rotates the secret once per key, replays the same secret, and keeps the receipt free of it', function (): void {
    $endpoint = endpointFixture();
    $headers = ['If-Match' => webhookTag($endpoint->getKey()), 'Idempotency-Key' => 'rot-1'];

    $first = $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/rotate-secret'), ['overlap_seconds' => 60], $headers)->assertOk();
    $again = $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/rotate-secret'), ['overlap_seconds' => 60], $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    $secret = $first->json('data.secret');
    expect($again->json('data.secret'))->toBe($secret)
        ->and($again->json('data'))->toBe($first->json('data'))
        ->and($endpoint->fresh()->signing_secret)->toBe($secret)
        ->and($endpoint->fresh()->version)->toBe(1)
        // One rotation: the previous secret is still the original, not the first rotation's.
        ->and($endpoint->fresh()->previous_signing_secret)->toBe(WebhookTestHelpers::SECRET)
        ->and($first->headers->get('Cache-Control'))->toContain('no-store');
    foreach (endpointReceiptBodies() as $body) {
        expect($body)->not->toContain($secret)->not->toContain(WebhookTestHelpers::SECRET);
    }
});

it('refuses to replay a rotation to a caller who may no longer manage endpoints', function (): void {
    $endpoint = endpointFixture();
    $headers = ['If-Match' => webhookTag($endpoint->getKey()), 'Idempotency-Key' => 'rot-2'];
    $first = $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/rotate-secret'), [], $headers)->assertOk();

    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', static fn (): bool => false);

    $denied = $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/rotate-secret'), [], $headers)->assertForbidden();
    expect($denied->getContent())->not->toContain($first->json('data.secret'));
});

it('deactivates once per key and raises the version once', function (): void {
    $endpoint = endpointFixture(['failure_count' => 3]);
    $headers = ['If-Match' => webhookTag($endpoint->getKey()), 'Idempotency-Key' => 'off-1'];

    $first = $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/deactivate'), [], $headers)->assertOk();
    $again = $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/deactivate'), [], $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('data'))->toBe($first->json('data'))
        ->and($first->json('data.status'))->toBe('disabled')
        ->and($endpoint->fresh()->version)->toBe(1);
});

it('replays a deletion after the endpoint is gone, on the scope recorded in its receipt', function (): void {
    $endpoint = endpointFixture();
    $id = $endpoint->getKey();
    $headers = ['If-Match' => webhookTag($id), 'Idempotency-Key' => 'del-1'];

    $this->deleteJson(V1::url('webhook-endpoints/'.$id), [], $headers)->assertNoContent();
    $this->deleteJson(V1::url('webhook-endpoints/'.$id), [], $headers)->assertNoContent()->assertHeader('Idempotent-Replayed', 'true');

    // The receipt holds the id and the scope the right was proven in, never the endpoint.
    $bodies = endpointReceiptBodies();
    expect($bodies)->toHaveCount(1);
    $stored = json_decode($bodies[0], true, flags: JSON_THROW_ON_ERROR);
    expect($stored['type'])->toBe('deleted')
        ->and($stored['deleted'])->toBe(['id' => (string) $id, 'tenant_id' => null, 'ability' => 'manage'])
        ->and($bodies[0])->not->toContain('hooks.test')->not->toContain('whsec_');
    // Without the key the endpoint is simply not found.
    $this->deleteJson(V1::url('webhook-endpoints/'.$id), [], ['If-Match' => webhookTag($id, 0)])->assertNotFound();
});

it('refuses to replay a deletion to a caller who may no longer manage endpoints', function (): void {
    $endpoint = endpointFixture();
    $id = $endpoint->getKey();
    $headers = ['If-Match' => webhookTag($id), 'Idempotency-Key' => 'del-2'];
    $this->deleteJson(V1::url('webhook-endpoints/'.$id), [], $headers)->assertNoContent();

    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', static fn (): bool => false);

    $this->deleteJson(V1::url('webhook-endpoints/'.$id), [], $headers)->assertForbidden();
});

it('judges a deletion receipt on its recorded scope: the tenant it was proven in, and the manage ability now', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    $resolver = new MutableTenantResolver;
    $resolver->tenant = 'A';
    app()->instance(TenantResolver::class, $resolver);
    $authorizer = new EndpointReplayAuthorizer(app(WebhookEndpointReadRepository::class), app(TenancyBoundary::class), '5');

    expect($authorizer->scope(new EndpointDeleteReceipt('5', 'A')))->toEqual(new ResourceRef('webhook_endpoints', 'A'))
        ->and($authorizer->scope(new EndpointDeleteReceipt('5', null)))->toEqual(new ResourceRef('webhook_endpoints', 'all'));
    $authorizer->authorize(null, new ResourceRef('webhook_endpoints', 'A'));
    // A receipt proven in another tenant, or outside any, is as unknown as a missing endpoint.
    expect(fn () => $authorizer->authorize(null, new ResourceRef('webhook_endpoints', 'B')))->toThrow(WebhookEndpointNotFoundException::class)
        ->and(fn () => $authorizer->authorize(null, new ResourceRef('webhook_endpoints', 'all')))->toThrow(WebhookEndpointNotFoundException::class);

    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', static fn (): bool => false);
    expect(fn () => $authorizer->authorize(null, new ResourceRef('webhook_endpoints', 'A')))->toThrow(AuthorizationException::class);
    MutableTenantResolver::clearFixtureStamps();
});

it('queues a redelivery at most once: a repeat finds the delivery pending and is refused', function (): void {
    Bus::fake([DeliverWebhook::class]);
    $endpoint = endpointFixture();
    $dead = app(VaulterDatabase::class)->write(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint, ['status' => 'dead']));
    $url = V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries/'.$dead->getKey().'/redeliver');

    $this->postJson($url)->assertStatus(202);
    $this->postJson($url)->assertStatus(409)->assertJsonPath('code', 'webhook_delivery_not_redeliverable');

    Bus::assertDispatchedTimes(DeliverWebhook::class, 1);
});

it('answers a keyed retry of a redelivery from its receipt and sends nothing, even once the delivery was delivered again', function (): void {
    $sent = 0;
    Http::fake(function () use (&$sent) {
        $sent++;

        return Http::response('', 200);
    });
    $endpoint = endpointFixture();
    $dead = app(VaulterDatabase::class)->write(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'attempts' => 10, 'next_retry_at' => null]));
    $url = V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries/'.$dead->getKey().'/redeliver');

    $first = $this->postJson($url, [], ['Idempotency-Key' => 'redeliver-1'])->assertStatus(202)->assertJsonPath('data.status', 'pending');
    WebhookTestHelpers::deliver($dead);
    expect($dead->refresh()->status)->toBe('delivered')->and($sent)->toBe(1);

    // The answer of the first request was lost: the retry under the same key replays it and queues nothing.
    $this->postJson($url, [], ['Idempotency-Key' => 'redeliver-1'])->assertStatus(202)
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.status', 'delivered');
    expect($dead->refresh()->status)->toBe('delivered')->and($dead->attempts)->toBe(1)->and($sent)->toBe(1);

    // A new key is a new redelivery.
    $this->postJson($url, [], ['Idempotency-Key' => 'redeliver-2'])->assertStatus(202)->assertJsonPath('data.status', 'pending');
});

it('replays a refused keyed redelivery as the same 409', function (): void {
    Bus::fake([DeliverWebhook::class]);
    $endpoint = endpointFixture();
    $pending = app(VaulterDatabase::class)->write(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint, ['status' => 'pending']));
    $url = V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries/'.$pending->getKey().'/redeliver');

    $this->postJson($url, [], ['Idempotency-Key' => 'redeliver-409'])->assertStatus(409)->assertJsonPath('code', 'webhook_delivery_not_redeliverable');
    $this->postJson($url, [], ['Idempotency-Key' => 'redeliver-409'])->assertStatus(409)->assertHeader('Idempotent-Replayed', 'true');

    Bus::assertNotDispatched(DeliverWebhook::class);
});

it('fingerprints a keyed update by its request: reordered keys replay, an omitted and a sent field are two requests', function (): void {
    $endpoint = endpointFixture();
    $headers = ['If-Match' => webhookTag($endpoint->getKey()), 'Idempotency-Key' => 'upd-shape'];
    $url = V1::url('webhook-endpoints/'.$endpoint->getKey());

    $first = $this->patchJson($url, ['url' => 'https://new.test/hook', 'events' => ['folder.created']], $headers)->assertOk();
    $this->patchJson($url, ['events' => ['folder.created'], 'url' => 'https://new.test/hook'], $headers)
        ->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.version', $first->json('data.version'));

    // The endpoint is active already, yet a sent is_active is another request than an omitted one.
    $this->patchJson($url, ['url' => 'https://new.test/hook', 'events' => ['folder.created'], 'is_active' => true], $headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_reused');

    expect($endpoint->fresh()->version)->toBe(1);
});
