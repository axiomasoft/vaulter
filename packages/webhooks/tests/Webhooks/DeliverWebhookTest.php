<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Webhooks\Actions\RotateWebhookSecretAction;
use Vaulter\Webhooks\Configuration\WebhooksConfig;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Models\WebhookEndpoint;
use Vaulter\Webhooks\Schema\WebhooksSchema;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\ValueObjects\PinnedOutboundEndpoint;
use Vaulter\Webhooks\WebhookRetryDispatcher;
use Vaulter\Webhooks\WebhookSecret;
use Vaulter\Webhooks\WebhookSigner;

/**
 * The reliable webhook delivery half (§2.11 / D14 / M3b), ported from chatom:
 * DeliverWebhook POSTs the signed payload over HTTP and persists the delivery's retry
 * bookkeeping. The signature follows Standard Webhooks: HMAC-SHA256 over
 * "{webhook-id}.{webhook-timestamp}.{body}", keyed by the decoded whsec_ secret.
 *
 * HTTP is faked (Http::fake), so no socket opens; the job runs synchronously by
 * resolving it and invoking handle() with the real WebhookSigner (whose output we
 * re-derive to assert the signature header byte-for-byte). Re-driving handle() walks
 * the delivery failed → … → dead exactly as a queue worker would on each retry.
 */
function runDeliver(WebhookDelivery $delivery): void
{
    (new DeliverWebhook(deliveryId: (string) $delivery->getKey()))
        ->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));
}

// ─────────────── 2xx — delivered, signed, endpoint touched ───────────────

it('marks the delivery delivered and sends the signed POST on a 2xx', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);

    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 3, 'failing_since' => now()->subDay()]);
    $delivery = WebhookTestHelpers::delivery($endpoint, ['payload' => ['id' => 'evt-1', 'type' => 'folder.created']]);

    runDeliver($delivery);

    $delivery->refresh();
    $endpoint->refresh();

    expect($delivery->status)->toBe('delivered')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_retry_at)->toBeNull()
        ->and($delivery->last_error)->toBeNull()
        ->and($endpoint->failure_count)->toBe(0)
        ->and($endpoint->failing_since)->toBeNull()
        ->and($endpoint->last_triggered_at)->toBeInstanceOf(Carbon::class);

    // Verify the signature byte-for-byte: HMAC-SHA256 of "{id}.{timestamp}.{body}" under the decoded key.
    Http::assertSent(function (Request $request): bool {
        $signature = $request->header('webhook-signature')[0];
        $timestamp = $request->header('webhook-timestamp')[0];
        $expected = 'v1,'.base64_encode(hash_hmac('sha256', "evt-1.{$timestamp}.{$request->body()}", WebhookSecret::keyBytes(WebhookTestHelpers::SECRET), true));

        return $request->url() === 'https://hooks.test/receive'
            && $request->method() === 'POST'
            && $request->header('webhook-id')[0] === 'evt-1'
            && $request->header('User-Agent')[0] === 'Vaulter-Webhooks/1'
            && $request->header('Content-Type')[0] === 'application/json'
            && $request->header('X-Vaulter-Event') === []
            && $request->header('X-Vaulter-Signature') === []
            && $signature === $expected;
    });

    Http::assertSentCount(1);
});

it('signs with the per-endpoint secret and binds id and timestamp (replay-resistant)', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);

    $secret = WebhookSecret::generate();
    $endpoint = WebhookTestHelpers::endpoint(['signing_secret' => $secret]);
    $delivery = WebhookTestHelpers::delivery($endpoint, ['payload' => ['id' => 'evt-2', 'type' => 'folder.created']]);

    runDeliver($delivery);

    Http::assertSent(function (Request $request) use ($secret): bool {
        $signature = $request->header('webhook-signature')[0];
        $timestamp = (int) $request->header('webhook-timestamp')[0];

        $signer = new WebhookSigner;

        // Changing the timestamp (a replay) or the id (another event) breaks the signature.
        $valid = $signature === $signer->sign('evt-2', $timestamp, $request->body(), $secret);
        $replayBroken = $signature !== $signer->sign('evt-2', $timestamp + 1, $request->body(), $secret);
        $idBroken = $signature !== $signer->sign('evt-3', $timestamp, $request->body(), $secret);

        return $valid && $replayBroken && $idBroken;
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
        ->and($endpoint->failure_count)->toBe(1)
        ->and($endpoint->failing_since)->not->toBeNull()
        ->and($endpoint->is_active)->toBeTrue();
});

it('records a safe transport classification when the POST throws', function (string $exceptionClass, string $classification): void {
    $secret = 'private-transport-secret-'.bin2hex(random_bytes(24));
    $content = 'private-payload +/='.bin2hex(random_bytes(24));
    $exception = new $exceptionClass('Bearer '.$secret.'?payload='.rawurlencode($content));
    $calls = 0;
    Http::fake(function () use ($exception, &$calls): never {
        $calls++;
        throw $exception;
    });

    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint);

    runDeliver($delivery);

    $delivery->refresh();

    expect($calls)->toBe(1)
        ->and($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_error)->toBe($classification)
        ->and($delivery->last_error)->not->toContain($secret)->not->toContain(rawurlencode($content))
        ->and($delivery->next_retry_at->isFuture())->toBeTrue()
        ->and($endpoint->fresh()->failure_count)->toBe(1);
})->with([
    'connection failure' => [ConnectionException::class, 'connection_error'],
    'other transport failure' => [RuntimeException::class, 'transport_error'],
]);

it('uses the configured retry schedule for next_retry_at', function (): void {
    config()->set('vaulter-webhooks.retry_schedule', [10, 30, 60, 300]);
    config()->set('vaulter-webhooks.retry_jitter', 0);

    Http::fake(['https://hooks.test/*' => Http::response('', 503)]);

    $this->freezeTime(function (): void {
        $endpoint = WebhookTestHelpers::endpoint();
        $delivery = WebhookTestHelpers::delivery($endpoint);

        runDeliver($delivery);

        // Attempt #1 uses schedule[0] = 10s (second-granular cast).
        expect($delivery->refresh()->next_retry_at->format('Y-m-d H:i:s'))
            ->toBe(now()->addSeconds(10)->format('Y-m-d H:i:s'));

        runDeliver($delivery);

        // Attempt #2 uses schedule[1] = 30s.
        expect($delivery->refresh()->next_retry_at->format('Y-m-d H:i:s'))
            ->toBe(now()->addSeconds(30)->format('Y-m-d H:i:s'));
    });
});

// ─────────────── schedule spent — dead (DLQ), endpoint failing run grows ───────────────

it('parks the delivery dead after the last scheduled retry and extends the endpoint failing run', function (): void {
    config()->set('vaulter-webhooks.retry_schedule', [1, 1]);
    config()->set('vaulter-webhooks.retry_jitter', 0);

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
        ->and($endpoint->failure_count)->toBe(3)
        ->and($endpoint->failing_since)->not->toBeNull()
        ->and($endpoint->is_active)->toBeTrue();

    Http::assertSentCount(3);
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
        ->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));

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
    $endpoint = WebhookTestHelpers::endpoint(['failure_count' => 2, 'failing_since' => now()]);
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

    $builder = app(WebhooksSchema::class);
    $panel = (new Profile)->key('default');
    $builder->create(entity: 'webhook_endpoints');
    $builder->create(entity: 'webhook_deliveries');

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
    config()->set('vaulter-webhooks.models.webhook_delivery', FailingSettlementWebhookDelivery::class);
    Http::fake(['*' => Http::response('', 200)]);

    expect(fn () => runDeliver($delivery))->toThrow(RuntimeException::class, 'settlement failed');
    expect($delivery->refresh()->status)->toBe('pending')
        ->and($delivery->attempts)->toBe(0)
        ->and($delivery->claim_token)->not->toBeNull()
        ->and($endpoint->refresh()->failure_count)->toBe(3);

    config()->set('vaulter-webhooks.models.webhook_delivery', WebhookDelivery::class);
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

it('sends an empty data as an object, signed over the exact bytes', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);
    $delivery = WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint(), ['payload' => ['id' => 'evt-empty', 'type' => 'node.renamed', 'data' => []]]);

    runDeliver($delivery);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"data":{}'));
});

it('signs with the secret stored when the request is built, not the one read before the claim', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);
    $endpoint = WebhookTestHelpers::endpoint();
    $delivery = WebhookTestHelpers::delivery($endpoint, ['payload' => ['id' => 'evt-rot', 'type' => 'node.renamed']]);
    $rotated = false;
    WebhookEndpoint::retrieved(function (WebhookEndpoint $row) use (&$rotated): void {
        if (!$rotated) {
            $rotated = true;
            app(RotateWebhookSecretAction::class)->execute($row->newQuery()->findOrFail($row->getKey()), overlapSeconds: 0);
        }
    });

    runDeliver($delivery);

    $new = $endpoint->refresh()->signing_secret;
    Http::assertSent(function (Request $request) use ($new): bool {
        $timestamp = $request->header('webhook-timestamp')[0];
        $expected = 'v1,'.base64_encode(hash_hmac('sha256', "evt-rot.{$timestamp}.{$request->body()}", WebhookSecret::keyBytes($new), true));

        return $request->header('webhook-signature')[0] === $expected;
    });
});

it('delivers when the endpoint is switched back on between the read and the kill', function (): void {
    Http::fake(['https://hooks.test/*' => Http::response('', 200)]);
    $endpoint = WebhookTestHelpers::endpoint(['is_active' => false, 'disabled_reason' => 'manual', 'disabled_at' => now()]);
    $delivery = WebhookTestHelpers::delivery($endpoint, ['payload' => ['id' => 'evt-on', 'type' => 'node.renamed']]);
    $enabled = false;
    WebhookEndpoint::retrieved(function (WebhookEndpoint $row) use (&$enabled): void {
        if (!$enabled && !$row->is_active) {
            $enabled = true;
            WebhookEndpoint::query()->whereKey($row->getKey())->toBase()->update(['is_active' => true, 'disabled_reason' => null, 'disabled_at' => null]);
        }
    });

    runDeliver($delivery);

    expect($delivery->refresh()->status)->toBe('delivered');
    Http::assertSentCount(1);
});

it('sends the signature headers through the cURL handler', function (): void {
    // Http::fake never builds Guzzle's cURL handler, so it cannot see the options that
    // handler rejects before the signed bytes are written. Loopback is pinned here only
    // so this process can read the request the handler actually sends.
    $portFile = tempnam(sys_get_temp_dir(), 'vaulter-wh-port-');
    $dumpFile = tempnam(sys_get_temp_dir(), 'vaulter-wh-dump-');
    expect($portFile)->toBeString()->and($dumpFile)->toBeString();
    file_put_contents($portFile, '');
    file_put_contents($dumpFile, '');

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', signatureCaptureServer(), $portFile, $dumpFile],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );

    try {
        expect(is_resource($process))->toBeTrue();
        $port = 0;
        for ($attempt = 0; $attempt < 50 && $port === 0; $attempt++) {
            $port = (int) file_get_contents((string) $portFile);
            if ($port === 0) {
                usleep(20_000);
            }
        }
        expect($port)->toBeGreaterThan(0);

        app()->instance(OutboundUrlGuard::class, new LoopbackDeliveryGuard);
        $endpoint = WebhookTestHelpers::endpoint(['url' => 'http://127.0.0.1:'.$port.'/hook']);
        $delivery = WebhookTestHelpers::delivery($endpoint, ['payload' => ['id' => 'evt-live', 'type' => 'folder.created']]);

        runDeliver($delivery);

        $raw = (string) file_get_contents((string) $dumpFile);
        expect($delivery->refresh()->status)->toBe('delivered')
            ->and($delivery->last_error)->toBeNull()
            ->and($raw)->toContain("\r\n\r\n");
        [$headers, $body] = explode("\r\n\r\n", $raw, 2);
        $headers = str_replace("\r\n", "\n", $headers);
        preg_match('/^webhook-timestamp: (\d+)$/m', $headers, $timestamp);
        preg_match('/^webhook-signature: (\S+)$/m', $headers, $signature);
        $expected = (new WebhookSigner)->sign(
            webhookId: 'evt-live',
            timestamp: (int) ($timestamp[1] ?? 0),
            body: $body,
            secret: WebhookTestHelpers::SECRET,
        );

        expect($headers)->toContain('webhook-id: evt-live')
            ->and($signature[1] ?? null)->toBe($expected);
    } finally {
        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
        }
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        if (is_string($portFile)) {
            @unlink($portFile);
        }
        if (is_string($dumpFile)) {
            @unlink($dumpFile);
        }
    }
});

function signatureCaptureServer(): string
{
    return <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    exit(1);
}
$name = stream_socket_get_name($server, false);
$port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
file_put_contents($argv[1], (string) $port);
$connection = stream_socket_accept($server, 5);
if ($connection === false) {
    exit(1);
}
$raw = '';
while (!str_contains($raw, "\r\n\r\n")) {
    $chunk = fread($connection, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $raw .= $chunk;
}
$length = 0;
if (preg_match('/^Content-Length: (\d+)$/mi', $raw, $match) === 1) {
    $length = (int) $match[1];
}
$split = strpos($raw, "\r\n\r\n");
$body = $split === false ? '' : substr($raw, $split + 4);
while (strlen($body) < $length) {
    $chunk = fread($connection, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $raw .= $chunk;
    $body .= $chunk;
}
file_put_contents($argv[2], $raw);
fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
fclose($connection);
PHP;
}

/** Pins the delivery onto the local capture socket. The real guard refuses loopback. */
final class LoopbackDeliveryGuard implements OutboundUrlGuard
{
    public function isAllowed(string $url): void {}

    public function pin(string $url): PinnedOutboundEndpoint
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'http';
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1';
        $port = is_array($parts) ? (int) ($parts['port'] ?? 80) : 80;

        return new PinnedOutboundEndpoint(scheme: $scheme, host: $host, ip: '127.0.0.1', port: $port);
    }
}
