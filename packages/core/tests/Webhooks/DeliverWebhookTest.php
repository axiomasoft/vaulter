<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Profiles\Profile;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookRetryDispatcher;
use Vaulter\Webhooks\WebhookSigner;

/**
 * The reliable webhook delivery half (§2.11 / D14 / M3b), ported from chatom:
 * DeliverWebhook POSTs the signed payload over HTTP and persists the delivery's retry
 * bookkeeping. The signature is HMAC-SHA256 over "{timestamp}.{body}" — replay-resistant.
 *
 * HTTP is faked (Http::fake), so no socket opens; the job runs synchronously by
 * resolving it and invoking handle() with the real WebhookSigner (whose sign() we
 * re-derive to assert the signature header byte-for-byte). Re-driving handle() walks
 * the delivery failed → … → dead exactly as a queue worker would on each retry.
 */
function runDeliver(WebhookDelivery $delivery): void
{
    (new DeliverWebhook(deliveryId: (string) $delivery->getKey()))
        ->handle(app(WebhookSigner::class), app(OutboundUrlGuard::class), app(VaulterConfig::class), app(WebhooksConfig::class));
}

// ─────────────── 2xx — delivered, signed, endpoint touched ───────────────

it('marks the delivery delivered and sends the signed POST on a 2xx', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);

    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 3]);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    runDeliver($delivery);

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe('delivered')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($delivery->last_error)->toBeNull()
        ->and($endpoint->failure_count)->toBe(0)
        ->and($endpoint->last_triggered_at)->toBeInstanceOf(Carbon::class);

    // Verify the HMAC signature byte-for-byte: HMAC-SHA256 of "{timestamp}.{body}".
    Http::assertSent(function (Request $request): bool {
        $signature = $request->header('X-Vaulter-Signature')[0];
        $timestamp = $request->header('X-Vaulter-Timestamp')[0];
        $expected = hash_hmac('sha256', "{$timestamp}.{$request->body()}", 'topsecret');

        return $request->url() === 'https://hooks.test/receive'
            && $request->method() === 'POST'
            && $request->header('X-Vaulter-Event')[0] === 'folder.created'
            && $signature === $expected;
    });

    Http::assertSentCount(1);
});

it('signs with the per-endpoint secret and binds the timestamp (replay-resistant)', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);

    $endpoint = WebhookTestHelpers::endpoint(['signing_secret' => 'per-endpoint-secret']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    runDeliver($delivery);

    Http::assertSent(function (Request $request): bool {
        $signature = $request->header('X-Vaulter-Signature')[0];
        $timestamp = (int) $request->header('X-Vaulter-Timestamp')[0];

        $signer = new WebhookSigner;

        // The signed material is "{ts}.{body}" — tampering with the timestamp (a replay)
        // breaks the signature.
        $valid = $signature === $signer->sign($request->body(), 'per-endpoint-secret', $timestamp);
        $replayBroken = $signature !== $signer->sign($request->body(), 'per-endpoint-secret', $timestamp + 1);

        return $valid && $replayBroken;
    });
});

// ─────────────── non-2xx — failed, attempts++, backoff ───────────────

it('marks the delivery failed and arms a future retry on a non-2xx', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('boom', 500)]);

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);

    runDeliver($delivery);

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_retry_at)->toBeInstanceOf(Carbon::class)
        ->and($delivery->next_retry_at->isFuture())->toBeTrue()
        ->and($delivery->last_error)->toBe('HTTP 500')
        ->and($endpoint->failure_count)->toBe(0)
        ->and($endpoint->is_active)->toBeTrue();
});

it('records the transport error message when the POST throws', function (): void {
    Http::fake(fn () => throw new ConnectionException('connection refused'));

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);

    runDeliver($delivery);

    $delivery->refresh();

    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_error)->toBe('connection refused')
        ->and($delivery->next_retry_at->isFuture())->toBeTrue();
});

it('uses the configured backoff schedule for next_retry_at', function (): void {
    config()->set('vaulter.webhooks.backoff', [10, 30, 60, 300]);
    config()->set('vaulter.webhooks.max_attempts', 5);

    Http::fake(['https://hooks.test/*' => Http::response('', 503)]);

    $this->freezeTime(function (): void {
        $endpoint = WebhookTestHelpers::endpoint();
        $delivery = WebhookTestHelpers::delivery($endpoint);

        runDeliver($delivery);

        // Attempt #1 uses backoff[0] = 10s (second-granular cast).
        expect($delivery->refresh()->next_retry_at->format('Y-m-d H:i:s'))
            ->toBe(now()->addSeconds(10)->format('Y-m-d H:i:s'));

        runDeliver($delivery);

        // Attempt #2 uses backoff[1] = 30s.
        expect($delivery->refresh()->next_retry_at->format('Y-m-d H:i:s'))
            ->toBe(now()->addSeconds(30)->format('Y-m-d H:i:s'));
    });
});

// ─────────────── max_attempts — dead (DLQ) + endpoint penalised ───────────────

it('parks the delivery dead after max_attempts and bumps the endpoint failure_count', function (): void {
    config()->set('vaulter.webhooks.max_attempts', 3);
    config()->set('vaulter.webhooks.disable_after_failures', 10);

    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);

    runDeliver($delivery);
    expect($delivery->refresh()->status)->toBe('failed');

    runDeliver($delivery);
    expect($delivery->refresh()->status)->toBe('failed');

    runDeliver($delivery);

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe('dead')
        ->and($delivery->attempts)->toBe(3)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($delivery->last_error)->toBe('HTTP 500')
        ->and($endpoint->failure_count)->toBe(1)
        ->and($endpoint->is_active)->toBeTrue();

    Http::assertSentCount(3);
});

it('auto-disables the endpoint once failure_count reaches disable_after_failures', function (): void {
    config()->set('vaulter.webhooks.max_attempts', 1);
    config()->set('vaulter.webhooks.disable_after_failures', 3);

    Http::fake(['https://hooks.test/*' => Http::response('', 500)]);

    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 2]);

    runDeliver(WebhookTestHelpers::delivery($endpoint));

    $endpoint->refresh();

    expect($endpoint->failure_count)->toBe(3)
        ->and($endpoint->is_active)->toBeFalse();
});

// ─────────────── terminal idempotency ───────────────

it('is a no-op when re-run on an already-delivered delivery', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);

    runDeliver($delivery);
    expect($delivery->refresh()->status)->toBe('delivered');

    Http::assertSentCount(1);

    runDeliver($delivery);

    Http::assertSentCount(1);
    expect($delivery->refresh()->attempts)->toBe(1);
});

it('is a no-op when the delivery row no longer exists', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);

    (new DeliverWebhook(deliveryId: '999999999'))
        ->handle(app(WebhookSigner::class), app(OutboundUrlGuard::class), app(VaulterConfig::class), app(WebhooksConfig::class));

    Http::assertNothingSent();
});

it('sends one POST when a duplicate job overlaps the HTTP attempt', function (int $status): void {
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);
    $posts = 0;

    Http::fake(function () use ($delivery, &$posts, $status) {
        $posts++;

        if ($posts === 1) {
            runDeliver($delivery);
        }

        return Http::response('', $status);
    });

    runDeliver($delivery);

    expect($posts)->toBe(1)
        ->and($delivery->refresh()->attempts)->toBe(1)
        ->and($delivery->status)->toBe($status === 200 ? 'delivered' : 'failed');
})->with([200, 500]);

it('keeps an active attempt out of the retry sweep and recovers an expired worker claim', function (): void {
    Bus::fake();
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint, [
        'claim_token' => str_repeat('a', 32),
        'claim_until' => now()->addMinute(),
        'next_retry_at' => now()->subMinute(),
    ]);
    Http::fake(['*' => Http::response('', 200)]);

    runDeliver($delivery);
    expect(app(WebhookRetryDispatcher::class)->redispatchDue())->toBe(0);
    Http::assertNothingSent();

    $this->travel(61)->seconds();
    expect(app(WebhookRetryDispatcher::class)->redispatchDue())->toBe(1);
    runDeliver($delivery);

    expect($delivery->refresh()->status)->toBe('delivered')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->claim_token)->toBeNull()
        ->and($delivery->claim_until)->toBeNull();
    Http::assertSentCount(1);
});

it('does not let an expired worker overwrite the replacement attempt', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 2]);
    $delivery = WebhookTestHelpers::delivery($endpoint);
    $posts = 0;
    Http::fake(function () use ($delivery, &$posts) {
        $posts++;

        if ($posts === 1) {
            // Simulate a paused worker resuming after the durable lease expired.
            $this->travel(120)->seconds();
            runDeliver($delivery);

            return Http::response('', 500);
        }

        return Http::response('', 200);
    });

    runDeliver($delivery);

    expect($posts)->toBe(2)
        ->and($delivery->refresh()->status)->toBe('delivered')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($delivery->last_error)->toBeNull()
        ->and($delivery->claim_token)->toBeNull()
        ->and($endpoint->refresh()->failure_count)->toBe(0);
});

it('delivers on the custom model connection without holding a transaction during HTTP', function (): void {
    $previousConnection = config('vaulter.database.connection');
    config()->set('database.connections.delivery_other', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('vaulter.database.connection', 'delivery_other');

    $builder = new SchemaBuilder;
    $panel = (new Profile)->id('default');
    $builder->createTable(entity: 'webhook_endpoints');
    $builder->createTable(entity: 'webhook_deliveries');

    $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint());
    Http::fake(function () {
        expect(DB::connection('delivery_other')->transactionLevel())->toBe(0);

        return Http::response('', 200);
    });

    try {
        runDeliver($delivery);
        expect($delivery->refresh()->status)->toBe('delivered')->and($delivery->claim_token)->toBeNull();
        Http::assertSentCount(1);
    } finally {
        config()->set('vaulter.database.connection', $previousConnection);
        DB::purge('delivery_other');
    }
});

it('retains a recoverable claim when custom model settlement throws and rolls back', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 3]);
    $delivery = WebhookTestHelpers::delivery($endpoint);
    config()->set('vaulter.models.webhook_delivery', FailingSettlementWebhookDelivery::class);
    Http::fake(['*' => Http::response('', 200)]);

    expect(fn () => runDeliver($delivery))->toThrow(RuntimeException::class, 'settlement failed');
    expect($delivery->refresh()->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->claim_token)->not->toBeNull()
        ->and($endpoint->refresh()->failure_count)->toBe(3);

    config()->set('vaulter.models.webhook_delivery', WebhookDelivery::class);
    $this->travel(120)->seconds();
    runDeliver($delivery);
    expect($delivery->refresh()->status)->toBe('delivered')->and($delivery->claim_token)->toBeNull();
});

final class FailingSettlementWebhookDelivery extends WebhookDelivery
{
    #[Override]
    public function save(array $options = []): bool
    {
        throw new RuntimeException('settlement failed');
    }
}
