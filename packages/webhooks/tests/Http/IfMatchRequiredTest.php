<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;

/**
 * Every route that changes an existing endpoint — update, deactivation, secret rotation, deletion — refuses to
 * run blind: without If-Match, or with `*` (which matches anything), it answers 428 and changes nothing; a stale tag is a
 * 412 stale_write; a caller who may not manage endpoints is answered 403 before any precondition.
 */
beforeEach(function (): void {
    config()->set('vaulter-webhooks.manage_ability');
    $this->actingAs(V1::user(1));
});

/**
 * @return array{0: string, 1: string, 2: array<string, mixed>, 3: int|string} method, path, body, endpoint id
 */
function imEndpointRequest(string $route): array
{
    $endpoint = app(VaulterDatabase::class)->write(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $id = $endpoint->getKey();

    return match ($route) {
        'webhook-endpoints.update' => ['PATCH', 'webhook-endpoints/'.$id, ['url' => 'https://changed.test/hook'], $id],
        'webhook-endpoints.deactivate' => ['POST', 'webhook-endpoints/'.$id.'/deactivate', [], $id],
        'webhook-endpoints.rotate-secret' => ['POST', 'webhook-endpoints/'.$id.'/rotate-secret', [], $id],
        'webhook-endpoints.destroy' => ['DELETE', 'webhook-endpoints/'.$id, [], $id],
    };
}

dataset('im routes', [
    'webhook-endpoints.update', 'webhook-endpoints.deactivate', 'webhook-endpoints.rotate-secret', 'webhook-endpoints.destroy',
]);

it('answers 428 without If-Match and leaves the endpoint as it was', function (string $route): void {
    [$method, $path, $body, $id] = imEndpointRequest($route);
    $before = WebhookEndpoint::query()->findOrFail($id)->getAttributes();

    $this->json($method, V1::url($path), $body)
        ->assertStatus(428)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta.header', 'If-Match');

    expect(WebhookEndpoint::query()->findOrFail($id)->getAttributes())->toEqual($before);
})->with('im routes');

it('answers 428 to If-Match: * because it would match any representation', function (string $route): void {
    [$method, $path, $body] = imEndpointRequest($route);

    $this->json($method, V1::url($path), $body, ['If-Match' => '*'])
        ->assertStatus(428)
        ->assertJsonPath('code', 'precondition_required')
        ->assertJsonPath('meta.reason', 'wildcard_not_allowed');
})->with('im routes');

it('answers a stale tag 412 stale_write and leaves the endpoint as it was', function (string $route): void {
    [$method, $path, $body, $id] = imEndpointRequest($route);
    $before = WebhookEndpoint::query()->findOrFail($id)->getAttributes();

    $this->json($method, V1::url($path), $body, ['If-Match' => webhookTag($id, 41)])
        ->assertStatus(412)
        ->assertJsonPath('code', 'stale_write');

    expect(WebhookEndpoint::query()->findOrFail($id)->getAttributes())->toEqual($before);
})->with('im routes');

it('answers an unknown endpoint 404 once the caller may manage endpoints', function (string $route): void {
    [$method, , $body] = imEndpointRequest($route);
    $path = str_contains($route, 'rotate') ? 'webhook-endpoints/987654/rotate-secret' : (str_contains($route, 'deactivate') ? 'webhook-endpoints/987654/deactivate' : 'webhook-endpoints/987654');

    $this->json($method, V1::url($path), $body, ['If-Match' => '"webhook-endpoint.987654.0"'])->assertNotFound()->assertJsonPath('code', 'webhook_endpoint_not_found');
})->with('im routes');

it('answers a caller who may not manage endpoints 403 before any precondition', function (string $route): void {
    [$method, $path, $body] = imEndpointRequest($route);
    config()->set('vaulter-webhooks.manage_ability', 'vaulter.webhooks.manage');
    Gate::define('vaulter.webhooks.manage', static fn (): bool => false);

    $this->json($method, V1::url($path), $body)->assertForbidden()->assertJsonPath('code', 'permission_denied');
    $this->json($method, V1::url($path), $body, ['If-Match' => '"stale"'])->assertForbidden();
})->with('im routes');

it('tags the endpoint with its id and version, and each change raises the version and the tag', function (): void {
    $endpoint = app(VaulterDatabase::class)->write(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $id = $endpoint->getKey();

    $shown = $this->getJson(V1::url('webhook-endpoints/'.$id))->assertOk()
        ->assertHeader('ETag', webhookTag($id, 0))
        ->assertJsonPath('data.version', 0)
        ->assertJsonPath('data.etag', webhookTag($id, 0));
    $updated = $this->patchJson(V1::url('webhook-endpoints/'.$id), ['events' => ['node.moved']], ['If-Match' => $shown->headers->get('ETag')])
        ->assertOk()->assertHeader('ETag', webhookTag($id, 1))->assertJsonPath('data.version', 1);
    $rotated = $this->postJson(V1::url('webhook-endpoints/'.$id.'/rotate-secret'), [], ['If-Match' => $updated->headers->get('ETag')])
        ->assertOk()->assertHeader('ETag', webhookTag($id, 2));
    $this->postJson(V1::url('webhook-endpoints/'.$id.'/deactivate'), [], ['If-Match' => $rotated->headers->get('ETag')])
        ->assertOk()->assertHeader('ETag', webhookTag($id, 3))->assertJsonPath('data.status', 'disabled');
    // The first tag is stale now, whichever route it is sent to.
    $this->deleteJson(V1::url('webhook-endpoints/'.$id), [], ['If-Match' => $shown->headers->get('ETag')])->assertStatus(412);
    expect(WebhookEndpoint::query()->whereKey($id)->exists())->toBeTrue();
});

it('raises the version when the worker switches a failing endpoint off, so a tag read while it was active cannot switch it back on', function (): void {
    config()->set('vaulter-webhooks.disable_after_days', 5);
    config()->set('vaulter-webhooks.retry_jitter', 0);
    config()->set('vaulter-webhooks.retry_schedule', array_fill(0, 40, 60));
    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);
    $endpoint = app(VaulterDatabase::class)->write(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $id = $endpoint->getKey();
    $delivery = app(VaulterDatabase::class)->write(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint));

    $shown = $this->getJson(V1::url('webhook-endpoints/'.$id))->assertOk()->assertHeader('ETag', webhookTag($id, 0));
    WebhookTestHelpers::deliver($delivery);
    expect($endpoint->refresh()->version)->toBe(0);
    $this->travel(5)->days();
    $this->travel(1)->seconds();
    WebhookTestHelpers::deliver($delivery);

    expect($endpoint->refresh()->is_active)->toBeFalse()
        ->and($endpoint->disabled_reason)->toBe('failing')
        ->and($endpoint->version)->toBe(1);
    $this->getJson(V1::url('webhook-endpoints/'.$id))->assertOk()
        ->assertHeader('ETag', webhookTag($id, 1))->assertJsonPath('data.status', 'disabled');
    $this->patchJson(V1::url('webhook-endpoints/'.$id), ['is_active' => true], ['If-Match' => $shown->headers->get('ETag')])
        ->assertStatus(412)->assertJsonPath('code', 'stale_write');
    expect($endpoint->refresh()->is_active)->toBeFalse()->and($endpoint->disabled_reason)->toBe('failing');
});
