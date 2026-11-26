<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Support\KeysetCursor;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
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
    config()->set('vaulter-webhooks.manage_ability');
    $this->actingAs(V1::user(1));
});

it('shows an endpoint without its secret and 404s an unknown one', function (): void {
    $endpoint = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint(['url' => 'https://show.test/hook']));

    $this->getJson(V1::url('webhook-endpoints/'.$endpoint->getKey()))
        ->assertOk()
        ->assertJsonPath('data.id', (string) $endpoint->getKey())
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissingPath('data.secret');
    $this->getJson(V1::url('webhook-endpoints/987654'))->assertNotFound()->assertJsonPath('code', 'webhook_endpoint_not_found');
});

it('rotates the signing secret and returns the new one once', function (): void {
    $endpoint = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $old = $endpoint->signing_secret;

    $rotated = $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/rotate-secret'), ['overlap_seconds' => 600], ['If-Match' => webhookTag($endpoint->getKey())])
        ->assertOk()
        ->assertJsonPath('data.id', (string) $endpoint->getKey());

    expect($rotated->json('data.secret'))->toStartWith('whsec_')->not->toBe($old)
        ->and($endpoint->fresh()->signing_secret)->toBe($rotated->json('data.secret'))
        ->and($endpoint->fresh()->previous_signing_secret)->toBe($old)
        ->and($rotated->headers->get('Cache-Control'))->toContain('no-store');
    $this->postJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/rotate-secret'), ['overlap_seconds' => -1], ['If-Match' => webhookTag($endpoint->getKey())])->assertUnprocessable();
});

it('pages the deliveries of one endpoint only', function (): void {
    $endpoint = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $other = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint(['url' => 'https://other.test/hook']));
    foreach (range(1, 3) as $i) {
        webhookFixture(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint, ['status' => 'failed', 'last_error' => 'HTTP 503', 'attempts' => $i]));
    }
    webhookFixture(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($other));

    $page = $this->getJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries?per_page=2'))->assertOk();

    expect($page->json('data'))->toHaveCount(2)
        ->and($page->json('meta.next_cursor'))->toBeString()
        ->and(array_keys($page->json('data.0')))->toBe(['id', 'event_id', 'event_type', 'status', 'attempts', 'next_retry_at', 'last_status_code', 'created_at'])
        ->and($page->json('data.0.last_status_code'))->toBe(503)
        ->and($page->json('data.0.event_type'))->toBe('folder.created');
    $rest = $this->getJson(V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries?per_page=2&cursor='.urlencode($page->json('meta.next_cursor'))))->assertOk();
    expect($rest->json('data'))->toHaveCount(1);
});

it('pages an endpoint history newest first through timestamp ties with a leading created_at bound', function (): void {
    $endpoint = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $expected = [];
    foreach (['2026-01-01 10:00:00', '2026-01-01 11:00:00', '2026-01-01 11:00:00', '2026-01-01 11:00:00', '2026-01-01 12:00:00'] as $at) {
        $expected[] = webhookFixture(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint, ['created_at' => $at, 'updated_at' => $at]));
    }
    usort($expected, static fn (WebhookDelivery $a, WebhookDelivery $b): int => [$b->created_at, $b->getKey()] <=> [$a->created_at, $a->getKey()]);

    $bounded = 0;
    DB::listen(static function (QueryExecuted $query) use (&$bounded): void {
        if (str_contains($query->sql, 'webhook_deliveries') && preg_match('/created_at[`"]? <=/', $query->sql) === 1) {
            $bounded++;
        }
    });
    $seen = [];
    $url = V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries?per_page=2');
    do {
        $page = $this->getJson($url)->assertOk();
        array_push($seen, ...array_column($page->json('data'), 'id'));
        $next = $page->json('meta.next_cursor');
        $url = V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries?per_page=2&cursor='.urlencode((string) $next));
    } while (is_string($next));

    expect($seen)->toBe(array_map(static fn (WebhookDelivery $delivery): string => (string) $delivery->getKey(), $expected))
        ->and($bounded)->toBe(2);
});

it('redelivers a dead delivery (202), refuses a pending one (409) and one of another endpoint (404)', function (): void {
    Bus::fake([DeliverWebhook::class]);
    $endpoint = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $other = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint(['url' => 'https://other.test/hook']));
    $dead = webhookFixture(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint, ['status' => 'dead', 'last_error' => 'connection refused']));
    $pending = webhookFixture(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint));
    $foreign = webhookFixture(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($other, ['status' => 'dead']));
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
    $endpoint = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $delivery = webhookFixture(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint, ['status' => 'dead']));
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

it('refuses a malformed or mismatched endpoints or deliveries cursor with 422 instead of failing the query', function (): void {
    $endpoint = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $forge = static fn (array $parameters): string => rtrim(strtr(base64_encode((string) json_encode($parameters)), '+/', '-_'), '=');
    $bad = [
        $forge(['id' => 1, '_pointsToNextItems' => true]),
        $forge(['created_at' => '2026-01-01 00:00:00', '_pointsToNextItems' => true]),
        $forge(['created_at' => 'soon', 'id' => 1, '_pointsToNextItems' => true]),
        'not-a-cursor',
    ];

    foreach (['webhook-endpoints', 'webhook-endpoints/'.$endpoint->getKey().'/deliveries'] as $path) {
        foreach ($bad as $cursor) {
            $this->getJson(V1::url($path.'?cursor='.urlencode($cursor)))
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['cursor']]);
        }
    }
});

it('pages every delivery once when legacy rows have no creation timestamp', function (): void {
    $endpoint = webhookFixture(static fn (): WebhookEndpoint => WebhookTestHelpers::endpoint());
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $row = webhookFixture(static fn (): WebhookDelivery => WebhookTestHelpers::delivery($endpoint));
        $ids[] = (string) $row->getKey();
        if ($i % 2 === 0) {
            webhookFixture(static fn (): int => $row->newQuery()->whereKey($row->getKey())->update(['created_at' => null]));
        }
    }
    $url = V1::url('webhook-endpoints/'.$endpoint->getKey().'/deliveries?per_page=1');
    $cursor = null;
    $seen = [];
    foreach ($ids as $_) {
        $page = $this->getJson($url.($cursor === null ? '' : '&cursor='.urlencode($cursor)))->assertOk();
        $seen[] = $page->json('data.0.id');
        $cursor = $page->json('meta.next_cursor');
    }
    expect($cursor)->toBeNull()->and($seen)->toEqualCanonicalizing($ids);

    $query = WebhookDelivery::query()->where('endpoint_id', $endpoint->getKey());
    $page = KeysetCursor::pageByCreationTime($query, null, 1, newestFirst: true);
    for ($i = 1; $i < count($seen); $i++) {
        $next = KeysetCursor::pageByCreationTime($query, $page->nextCursor()->encode(), 1, newestFirst: true);
        $previous = KeysetCursor::pageByCreationTime($query, $next->previousCursor()->encode(), 1, newestFirst: true);
        expect((string) $previous->items()[0]->getKey())->toBe($seen[$i - 1]);
        $page = $next;
    }
});
