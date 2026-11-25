<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Packman\Config\Exceptions\InvalidConfiguration;
use Psr\Http\Message\StreamInterface;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\Webhooks\Configuration\WebhooksOptions;
use Vaulter\Webhooks\Contracts\HostResolver;
use Vaulter\Webhooks\Contracts\OutboundUrlGuard;
use Vaulter\Webhooks\Exceptions\BlockedOutboundUrlException;
use Vaulter\Webhooks\Jobs\DeliverWebhook;
use Vaulter\Webhooks\Models\WebhookDelivery;
use Vaulter\Webhooks\Testing\FakeHostResolver;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\ValueObjects\PinnedOutboundEndpoint;
use Vaulter\Webhooks\WebhookSigner;

/**
 * The outbound SSRF guard: the endpoint URL is a
 * user-controlled primitive, so the relay resolves it (A + AAAA), refuses any address
 * in a private/loopback/link-local/reserved range on the DELIVERY path, and pins the
 * vetted IP onto the connection. No real DNS or socket is touched — the resolver is
 * faked (TestCase binds FakeHostResolver) and HTTP is faked.
 */
function deliverGuarded(WebhookDelivery $delivery): void
{
    new DeliverWebhook(deliveryId: (string) $delivery->getKey())
        ->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), webhooksConfig: app(WebhooksOptions::class), database: app(VaulterDatabase::class));
}

function fakeResolver(): FakeHostResolver
{
    /** @var FakeHostResolver $resolver */
    $resolver = app(HostResolver::class);

    return $resolver;
}

// ─────────────── delivery path is the real barrier ───────────────

it('refuses to POST to the cloud-metadata address on the delivery path', function (): void {
    Http::fake();

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'http://169.254.169.254/latest/meta-data']);
    $delivery = WebhookTestHelpers::delivery($endpoint);
    $realGuard = app(OutboundUrlGuard::class);
    $blocked = null;
    $guard = Mockery::mock(OutboundUrlGuard::class);
    $guard->shouldReceive('pin')->once()->with($endpoint->url)
        ->andReturnUsing(function (string $url) use ($realGuard, &$blocked): PinnedOutboundEndpoint {
            try {
                return $realGuard->pin($url);
            } catch (BlockedOutboundUrlException $exception) {
                $blocked = $exception;
                throw $exception;
            }
        });
    app()->instance(OutboundUrlGuard::class, $guard);

    deliverGuarded($delivery);

    Http::assertNothingSent();

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($blocked)->toBeInstanceOf(BlockedOutboundUrlException::class)
        ->and($blocked?->getMessage())->toContain('blocked address')
        ->and($delivery->last_error)->toBe('transport_error')
        ->and($delivery->last_error)->not->toContain('169.254.169.254');
});

it('refuses a host that resolves to loopback', function (): void {
    Http::fake();
    fakeResolver()->set('evil.test', ['127.0.0.1']);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://evil.test/hook']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe('failed');
});

it('refuses a host whose public A record is paired with a private AAAA record', function (): void {
    Http::fake();
    // A public IPv4 but a loopback IPv6 — ANY blocked record must sink the delivery.
    fakeResolver()->set('mixed.test', [FakeHostResolver::PUBLIC_IP, '::1']);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://mixed.test/hook']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe('failed');
});

it('refuses an IPv4-mapped IPv6 loopback address', function (): void {
    Http::fake();
    fakeResolver()->set('mapped.test', ['::ffff:127.0.0.1']);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://mapped.test/hook']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe('failed');
});

it('does not follow a redirect onto a private address', function (): void {
    // Redirects are off, so a 302 is the final (non-2xx) response and the metadata
    // target is never requested.
    Http::fake([
        'https://hooks.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
        'http://169.254.169.254/*' => Http::response('', 200),
    ]);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/receive']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
    expect($delivery->refresh()->status)->toBe('failed');
});

/*
 * The barrier used to rest ENTIRELY on the `false` default of the
 * `webhooks.follow_redirects` config key: by enabling it a host dropped the SSRF protection — `CURLOPT_RESOLVE`
 * pins only the ORIGINAL host:port, while a redirect hop is resolved again, without the guard or the pin.
 * The receiver answers `302 Location: http://169.254.169.254/…` and the request goes to the metadata service.
 * The key is REMOVED and redirects are never followed. The test hits exactly that point: the host TRIES
 * to enable them.
 */
it('legacy follow_redirects=true key does NOT enable redirects: a 302 to metadata is not followed', function (): void {
    // A host (or its legacy config) tries to bring the old behaviour back.
    expect(fn (): WebhooksOptions => WebhooksOptions::fromArray(['delivery' => ['follow_redirects' => true]]))
        ->toThrow(InvalidConfiguration::class, 'vaulter-webhooks.delivery.follow_redirects');

    Http::fake([
        'https://hooks.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        'http://169.254.169.254/*' => Http::response('secret-token', 200),
    ]);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/receive']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    // Before the fix the Guzzle redirect middleware (Http::fake builds the stack through
    // HandlerStack::create()) really followed the 302, so a request to metadata arrived here.
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));

    // The 302 is a final non-2xx answer: the delivery does not count as successful.
    expect($delivery->refresh()->status)->toBe('failed');
});

it('delivers to a public host, pinning the vetted IP onto the connection', function (): void {
    Http::fake(['https://good.test/*' => Http::response('', 200)]);
    fakeResolver()->set('good.test', [FakeHostResolver::PUBLIC_IP]);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://good.test/hook']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    expect($delivery->refresh()->status)->toBe('delivered');
    Http::assertSentCount(1);
});

// ─────────────── reserved / multicast / unspecified ranges ───────────────────────────

it('refuses the IPv6 unspecified address :: which routes to loopback on Linux', function (): void {
    // 0.0.0.0/8 blocked IPv4 "this-network" but its IPv6 twin `::` was a hole: [::]
    // connects to loopback on Linux. The blocklist now carries ::/128.
    fakeResolver()->set('unspec.test', ['::']);

    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('https://unspec.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
});

it('refuses the IPv4 limited-broadcast / reserved-future range', function (): void {
    // 255.255.255.255 and the whole 240.0.0.0/4 reserved block were unlisted.
    fakeResolver()->set('bcast.test', ['255.255.255.255']);

    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('https://bcast.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
});

it('refuses IPv4 multicast addresses', function (): void {
    fakeResolver()->set('mcast4.test', ['224.0.0.1']);

    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('https://mcast4.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
});

it('refuses IPv6 multicast addresses', function (): void {
    fakeResolver()->set('mcast6.test', ['ff02::1']);

    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('https://mcast6.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
});

// ─────────────── guard unit ───────────────

it('refuses a non-http(s) scheme', function (): void {
    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('file:///etc/passwd'))
        ->toThrow(BlockedOutboundUrlException::class);
});

it('pins the vetted public IP, host and port for delivery', function (): void {
    fakeResolver()->set('good.test', [FakeHostResolver::PUBLIC_IP]);

    $pinned = app(OutboundUrlGuard::class)->pin('https://good.test/hook');

    expect($pinned->ip)->toBe(FakeHostResolver::PUBLIC_IP)
        ->and($pinned->host)->toBe('good.test')
        ->and($pinned->port)->toBe(443)
        ->and($pinned->curlResolveEntry())->toBe('good.test:443:'.FakeHostResolver::PUBLIC_IP);
});

// ─────────────── registration early-reject ───────────────

it('rejects registering an endpoint whose host resolves into a private range', function (): void {
    config()->set('vaulter-webhooks.manage_ability');
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    fakeResolver()->set('internal.test', ['10.0.0.5']);

    $this->postJson('api/vaulter/v1/webhook-endpoints', [
        'url' => 'https://internal.test/hook',
        'events' => ['folder.created'],
    ])->assertStatus(422)->assertJsonValidationErrors('url');
});

// ─────────────── embedded IPv4, test ranges, proxy, response budget ───────────────

it('blocks IPv4-embedding IPv6, TEST-NET and benchmark addresses by default', function (string $ip): void {
    fakeResolver()->set('embedded.test', [$ip]);

    expect(fn () => app(OutboundUrlGuard::class)->pin('https://embedded.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
})->with([
    '::ffff:7f00:1', '64:ff9b::7f00:1', '64:ff9b:1::7f00:1', '2002:7f00:1::', '2001:0:7f00:1::1', '::127.0.0.1', '::7f00:1',
    '192.0.0.8', '192.0.2.1', '198.18.0.1', '198.19.255.255', '198.51.100.7', '203.0.113.9', '2001:db8::1', '100::1',
]);

it('keeps public IPv4 and IPv6 destinations and mixed-case scheme as positive controls', function (): void {
    fakeResolver()->set('pub.test', [FakeHostResolver::PUBLIC_IP, '2606:4700:4700::1111']);

    expect(app(OutboundUrlGuard::class)->pin('HtTpS://pub.test/hook')->port)->toBe(443);
});

it('rejects a mixed public/private A+AAAA answer and treats userinfo and fragments unambiguously', function (): void {
    fakeResolver()->set('mix.test', [FakeHostResolver::PUBLIC_IP, '64:ff9b::a00:1']);
    $guard = app(OutboundUrlGuard::class);

    expect(fn () => $guard->pin('https://mix.test/'))->toThrow(BlockedOutboundUrlException::class)
        ->and(fn () => $guard->pin('http://user:pass@127.0.0.1/'))->toThrow(BlockedOutboundUrlException::class)
        ->and(fn () => $guard->pin('http://127.0.0.1#@example.com/'))->toThrow(BlockedOutboundUrlException::class)
        ->and(fn () => $guard->pin('unix:///var/run/docker.sock'))->toThrow(BlockedOutboundUrlException::class)
        ->and(fn () => $guard->pin('gopher://pub.test/'))->toThrow(BlockedOutboundUrlException::class);
});

it('keeps an explicit allowed_ip_ranges host override above the new defaults', function (): void {
    config(['vaulter-webhooks.delivery.allowed_ip_ranges' => ['198.51.100.0/24']]);
    fakeResolver()->set('lab.test', ['198.51.100.7']);

    expect(app(OutboundUrlGuard::class)->pin('https://lab.test/')->ip)->toBe('198.51.100.7');
});

it('never lets a malformed allowed range open loopback or link-local, and a valid one still rescues its own range only', function (string $range): void {
    config(['vaulter-webhooks.delivery.allowed_ip_ranges' => [$range]]);
    fakeResolver()->set('meta.test', ['169.254.169.254']);
    fakeResolver()->set('local.test', ['127.0.0.1']);

    foreach (['meta.test', 'local.test'] as $host) {
        expect(fn () => app(OutboundUrlGuard::class)->pin('http://'.$host.'/'))->toThrow(InvalidConfiguration::class);
    }
})->with(['10.0.0.0/nope', '10.0.0.0/-1', '10.0.0.0/', '10.0.0.0/99', '::/nope', '::/']);

it('keeps the guard on a valid allowed IPv6 range and a bare allowed address while the rest stays blocked', function (): void {
    config(['vaulter-webhooks.delivery.allowed_ip_ranges' => ['2001:db8::/32', '198.51.100.7']]);
    fakeResolver()->set('v6.test', ['2001:db8::5']);
    fakeResolver()->set('v4.test', ['198.51.100.7']);
    fakeResolver()->set('lo.test', ['127.0.0.1']);

    expect(app(OutboundUrlGuard::class)->pin('https://v6.test/')->ip)->toBe('2001:db8::5')
        ->and(app(OutboundUrlGuard::class)->pin('https://v4.test/')->ip)->toBe('198.51.100.7')
        ->and(fn () => app(OutboundUrlGuard::class)->pin('https://lo.test/'))->toThrow(BlockedOutboundUrlException::class);
});

it('transport options disable every env proxy, redirects and decoding and keep the pin', function (): void {
    $names = ['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'];
    $saved = array_map(fn (string $n): string|false => getenv($n), array_combine($names, $names));

    try {
        foreach ($names as $n) {
            putenv($n.'=http://127.0.0.1:3128');
        }

        fakeResolver()->set('pub.test', [FakeHostResolver::PUBLIC_IP]);
        $job = new DeliverWebhook(deliveryId: 'x');
        $config = new ReflectionProperty($job, 'webhooksConfig');
        $config->setValue($job, app(WebhooksOptions::class));
        $method = new ReflectionMethod($job, 'transportOptions');
        $options = $method->invoke($job, app(OutboundUrlGuard::class)->pin('https://pub.test/'));
    } finally {
        foreach ($saved as $n => $v) {
            putenv($v === false ? $n : $n.'='.$v);
        }
    }

    expect($options['proxy'])->toBe('')
        ->and($options['decode_content'])->toBeFalse()
        ->and($options['curl'][CURLOPT_FOLLOWLOCATION])->toBeFalse()
        ->and($options['curl'][CURLOPT_CONNECT_TO])->toBe(['pub.test:443:'.FakeHostResolver::PUBLIC_IP.':443'])
        ->and($options['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue()
        ->and($options['curl'][CURLOPT_FORBID_REUSE])->toBeTrue()
        ->and($options['curl'])->not->toHaveKey(CURLOPT_RESOLVE)
        ->and($options['sink'])->toBeInstanceOf(StreamInterface::class);
});

it('re-pins on every attempt after DNS changes', function (): void {
    fakeResolver()->set('rot.test', [FakeHostResolver::PUBLIC_IP]);
    $guard = app(OutboundUrlGuard::class);
    $first = $guard->pin('https://rot.test/');
    fakeResolver()->set('rot.test', ['10.0.0.5']);

    expect($first->ip)->toBe(FakeHostResolver::PUBLIC_IP)
        ->and(fn () => $guard->pin('https://rot.test/'))->toThrow(BlockedOutboundUrlException::class);
});

it('refuses ambiguous or unapproved authorities before transport', function (string $url): void {
    fakeResolver()->set('example.com', [FakeHostResolver::PUBLIC_IP]);

    expect(fn () => app(OutboundUrlGuard::class)->pin($url))->toThrow(BlockedOutboundUrlException::class);
})->with(['https://example.com./hook', 'https://8.8.8.8./hook', 'https://user@example.com/hook', 'https://@example.com/hook', 'https://example.com:8443/hook']);

it('pins an IDNA host and brackets IPv6 endpoints for cURL', function (): void {
    fakeResolver()->set('xn--bcher-kva.test', ['2606:4700:4700::1111']);

    expect(app(OutboundUrlGuard::class)->pin('https://bücher.test/hook')->curlConnectToEntry())
        ->toBe('xn--bcher-kva.test:443:[2606:4700:4700::1111]:443')
        ->and(app(OutboundUrlGuard::class)->pin('https://[2606:4700:4700::1111]/')->curlConnectToEntry())
        ->toBe('[2606:4700:4700::1111]:443:[2606:4700:4700::1111]:443');
});
