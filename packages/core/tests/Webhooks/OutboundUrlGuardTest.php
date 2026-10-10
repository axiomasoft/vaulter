<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Configuration\WebhooksConfig;
use Vaulter\Contracts\Webhooks\HostResolver;
use Vaulter\Contracts\Webhooks\OutboundUrlGuard;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Webhooks\BlockedOutboundUrlException;
use Vaulter\Jobs\Webhooks\DeliverWebhook;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Tests\Webhooks\FakeHostResolver;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookSigner;

/**
 * The outbound SSRF guard (§2.11 / D5 / N2 / F-P1.7-01): the endpoint URL is a
 * user-controlled primitive, so the relay resolves it (A + AAAA), refuses any address
 * in a private/loopback/link-local/reserved range on the DELIVERY path, and pins the
 * vetted IP onto the connection. No real DNS or socket is touched — the resolver is
 * faked (TestCase binds FakeHostResolver) and HTTP is faked.
 */
function deliverGuarded(WebhookDelivery $delivery): void
{
    (new DeliverWebhook(deliveryId: (string) $delivery->getKey()))
        ->handle(signer: app(WebhookSigner::class), urlGuard: app(OutboundUrlGuard::class), vaulterConfig: app(VaulterConfig::class), webhooksConfig: app(WebhooksConfig::class), database: app(VaulterDatabase::class));
}

function fakeResolver(): FakeHostResolver
{
    /** @var FakeHostResolver $resolver */
    $resolver = app(HostResolver::class);

    return $resolver;
}

// ─────────────── delivery path is the real barrier ───────────────

it('refuses to POST to the cloud-metadata address on the delivery path, F-P1.7-01', function (): void {
    Http::fake();

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'http://169.254.169.254/latest/meta-data']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    Http::assertNothingSent();

    expect($delivery->refresh()->status)->toBe('failed')
        ->and($delivery->last_error)->toContain('blocked');
});

it('refuses a host that resolves to loopback, F-P1.7-01', function (): void {
    Http::fake();
    fakeResolver()->set('evil.test', ['127.0.0.1']);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://evil.test/hook']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe('failed');
});

it('refuses a host whose public A record is paired with a private AAAA record, F-P1.7-01', function (): void {
    Http::fake();
    // A public IPv4 but a loopback IPv6 — ANY blocked record must sink the delivery.
    fakeResolver()->set('mixed.test', [FakeHostResolver::PUBLIC_IP, '::1']);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://mixed.test/hook']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe('failed');
});

it('refuses an IPv4-mapped IPv6 loopback address, F-P1.7-01', function (): void {
    Http::fake();
    fakeResolver()->set('mapped.test', ['::ffff:127.0.0.1']);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://mapped.test/hook']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe('failed');
});

it('does not follow a redirect onto a private address, F-P1.7-01', function (): void {
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
 * VLT-REMED P5.2 / R-05 (D30). Барьер держался ИСКЛЮЧИТЕЛЬНО на дефолте `false` конфиг-ключа
 * `webhooks.follow_redirects`: включив его, хост снимал SSRF-защиту целиком — `CURLOPT_RESOLVE`
 * пинит только ИСХОДНЫЙ host:port, а redirect-хоп резолвится заново, без guard'а и без пина.
 * Приёмник отвечает `302 Location: http://169.254.169.254/…` — и запрос уходит на metadata.
 * Ключ УДАЛЁН, редиректы не следуются безусловно. Тест бьёт ровно в эту точку: хост ПЫТАЕТСЯ
 * их включить.
 */
it('устаревший ключ follow_redirects=true НЕ включает редиректы — 302 на metadata не следуется, R-05', function (): void {
    // Хост (или его легаси-конфиг) пытается вернуть старое поведение.
    config(['vaulter.webhooks.delivery.follow_redirects' => true]);

    Http::fake([
        'https://hooks.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        'http://169.254.169.254/*' => Http::response('secret-token', 200),
    ]);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://hooks.test/receive']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    // На коде ДО фикса Guzzle-овский redirect-middleware (Http::fake строит стек через
    // HandlerStack::create()) реально шёл по 302 → сюда прилетал запрос к metadata.
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));

    // 302 — финальный не-2xx ответ: доставка не считается успешной.
    expect($delivery->refresh()->status)->toBe('failed');
});

it('ключа follow_redirects больше нет в шипуемом конфиге, R-05', function (): void {
    // Ground truth: ключ удалён из config/vaulter.php, а не просто игнорируется кодом.
    $config = (string) file_get_contents(dirname(__DIR__, 3).'/core/config/vaulter.php');

    expect($config)->not->toContain("'follow_redirects' =>");
});

it('delivers to a public host, pinning the vetted IP onto the connection, F-P1.7-01', function (): void {
    Http::fake(['https://good.test/*' => Http::response('', 200)]);
    fakeResolver()->set('good.test', [FakeHostResolver::PUBLIC_IP]);

    $endpoint = WebhookTestHelpers::endpoint(['url' => 'https://good.test/hook']);
    $delivery = WebhookTestHelpers::delivery($endpoint);

    deliverGuarded($delivery);

    expect($delivery->refresh()->status)->toBe('delivered');
    Http::assertSentCount(1);
});

// ─────────────── reserved / multicast / unspecified ranges (review P0.2) ───────────────

it('refuses the IPv6 unspecified address :: which routes to loopback on Linux, F-P1.7-01', function (): void {
    // 0.0.0.0/8 blocked IPv4 "this-network" but its IPv6 twin `::` was a hole: [::]
    // connects to loopback on Linux. The blocklist now carries ::/128.
    fakeResolver()->set('unspec.test', ['::']);

    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('https://unspec.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
});

it('refuses the IPv4 limited-broadcast / reserved-future range, F-P1.7-01', function (): void {
    // 255.255.255.255 and the whole 240.0.0.0/4 reserved block were unlisted.
    fakeResolver()->set('bcast.test', ['255.255.255.255']);

    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('https://bcast.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
});

it('refuses IPv4 multicast addresses, F-P1.7-01', function (): void {
    fakeResolver()->set('mcast4.test', ['224.0.0.1']);

    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('https://mcast4.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
});

it('refuses IPv6 multicast addresses, F-P1.7-01', function (): void {
    fakeResolver()->set('mcast6.test', ['ff02::1']);

    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('https://mcast6.test/hook'))
        ->toThrow(BlockedOutboundUrlException::class);
});

// ─────────────── guard unit ───────────────

it('refuses a non-http(s) scheme, F-P1.7-01', function (): void {
    expect(fn () => app(OutboundUrlGuard::class)->isAllowed('file:///etc/passwd'))
        ->toThrow(BlockedOutboundUrlException::class);
});

it('pins the vetted public IP, host and port for delivery, F-P1.7-01', function (): void {
    fakeResolver()->set('good.test', [FakeHostResolver::PUBLIC_IP]);

    $pinned = app(OutboundUrlGuard::class)->pin('https://good.test/hook');

    expect($pinned->ip)->toBe(FakeHostResolver::PUBLIC_IP)
        ->and($pinned->host)->toBe('good.test')
        ->and($pinned->port)->toBe(443)
        ->and($pinned->curlResolveEntry())->toBe('good.test:443:'.FakeHostResolver::PUBLIC_IP);
});

// ─────────────── registration early-reject ───────────────

it('rejects registering an endpoint whose host resolves into a private range, F-P1.7-01', function (): void {
    config()->set('vaulter.webhooks.manage_ability', null);
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    fakeResolver()->set('internal.test', ['10.0.0.5']);

    $this->postJson('api/vaulter/webhook-endpoints', [
        'url' => 'https://internal.test/hook',
        'events' => ['folder.created'],
    ])->assertStatus(422)->assertJsonValidationErrors('url');
});
