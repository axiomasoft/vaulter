<?php

declare(strict_types=1);

use Vaulter\Exceptions\Webhooks\InvalidWebhookSignatureException;
use Vaulter\Webhooks\StandardWebhookVerifier;
use Vaulter\Webhooks\WebhookSecret;
use Vaulter\Webhooks\WebhookSigner;

const VERIFIER_NOW = 1_700_000_000;

/** @return array{headers: array<string, string>, body: string, secret: string} */
function signedRequest(?string $secret = null, int $timestamp = VERIFIER_NOW, string $body = '{"id":"evt-1"}'): array
{
    $secret ??= WebhookSecret::generate();

    return [
        'headers' => [
            'webhook-id' => 'evt-1',
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => (new WebhookSigner)->sign('evt-1', $timestamp, $body, $secret),
        ],
        'body' => $body,
        'secret' => $secret,
    ];
}

it('accepts a request signed by a trusted secret', function (): void {
    $request = signedRequest();

    (new StandardWebhookVerifier)->verify($request['headers'], $request['body'], [$request['secret']], now: VERIFIER_NOW);

    expect(true)->toBeTrue();
});

it('accepts header names in any case and list-valued headers', function (): void {
    $request = signedRequest();
    $headers = [];
    foreach ($request['headers'] as $name => $value) {
        $headers[strtoupper($name)] = [$value];
    }

    (new StandardWebhookVerifier)->verify($headers, $request['body'], [$request['secret']], now: VERIFIER_NOW);

    expect(true)->toBeTrue();
});

it('rejects a tampered body, a forged signature and a wrong secret', function (string $case): void {
    $request = signedRequest();
    $secrets = [$request['secret']];

    if ($case === 'body') {
        $request['body'] .= ' ';
    } elseif ($case === 'signature') {
        $request['headers']['webhook-signature'] = 'v1,'.base64_encode(random_bytes(32));
    } else {
        $secrets = [WebhookSecret::generate()];
    }

    expect(fn () => (new StandardWebhookVerifier)->verify($request['headers'], $request['body'], $secrets, now: VERIFIER_NOW))
        ->toThrow(InvalidWebhookSignatureException::class, 'no signature matches');
})->with(['body', 'signature', 'secret']);

it('accepts the timestamp tolerance edge in both directions and rejects one second beyond', function (int $offset, bool $accepted): void {
    $request = signedRequest(timestamp: VERIFIER_NOW + $offset);
    $verify = fn () => (new StandardWebhookVerifier)->verify($request['headers'], $request['body'], [$request['secret']], now: VERIFIER_NOW);

    if ($accepted) {
        $verify();
        expect(true)->toBeTrue();
    } else {
        expect($verify)->toThrow(InvalidWebhookSignatureException::class, 'tolerance');
    }
})->with([
    'past edge' => [-300, true],
    'future edge' => [300, true],
    'too old' => [-301, false],
    'too new' => [301, false],
]);

it('honours a custom tolerance', function (): void {
    $request = signedRequest(timestamp: VERIFIER_NOW - 60);

    expect(fn () => (new StandardWebhookVerifier)->verify($request['headers'], $request['body'], [$request['secret']], toleranceSeconds: 30, now: VERIFIER_NOW))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('requires all three headers and a numeric timestamp', function (string $missing): void {
    $request = signedRequest();

    if ($missing === 'timestamp-format') {
        $request['headers']['webhook-timestamp'] = 'yesterday';
    } else {
        unset($request['headers'][$missing]);
    }

    expect(fn () => (new StandardWebhookVerifier)->verify($request['headers'], $request['body'], [$request['secret']], now: VERIFIER_NOW))
        ->toThrow(InvalidWebhookSignatureException::class);
})->with(['webhook-id', 'webhook-timestamp', 'webhook-signature', 'timestamp-format']);

it('accepts any one of several signatures and any one of several secrets', function (): void {
    $old = WebhookSecret::generate();
    $new = WebhookSecret::generate();
    $signer = new WebhookSigner;
    $body = '{"id":"evt-1"}';

    $headers = [
        'webhook-id' => 'evt-1',
        'webhook-timestamp' => (string) VERIFIER_NOW,
        // v2 and unrelated signatures are ignored; the one that matches is last.
        'webhook-signature' => 'v2,ignored v1,'.base64_encode(random_bytes(32)).' '.$signer->sign('evt-1', VERIFIER_NOW, $body, $old),
    ];

    (new StandardWebhookVerifier)->verify($headers, $body, [$new, $old], now: VERIFIER_NOW);

    expect(true)->toBeTrue();
});

it('skips an unusable secret instead of failing the whole check', function (): void {
    $request = signedRequest();

    (new StandardWebhookVerifier)->verify($request['headers'], $request['body'], ['not-a-secret', $request['secret']], now: VERIFIER_NOW);

    expect(true)->toBeTrue();
});

it('verifies what DeliverWebhook-style signing sends, during a rotation overlap', function (): void {
    $old = WebhookSecret::generate();
    $new = WebhookSecret::generate();
    $body = '{"id":"evt-1"}';
    $headers = [
        'webhook-id' => 'evt-1',
        'webhook-timestamp' => (string) VERIFIER_NOW,
        'webhook-signature' => (new WebhookSigner)->signatureHeader('evt-1', VERIFIER_NOW, $body, [$new, $old]),
    ];

    // A receiver that still has only the old secret keeps working, and so does one with only the new.
    (new StandardWebhookVerifier)->verify($headers, $body, [$old], now: VERIFIER_NOW);
    (new StandardWebhookVerifier)->verify($headers, $body, [$new], now: VERIFIER_NOW);

    expect(true)->toBeTrue();
});
