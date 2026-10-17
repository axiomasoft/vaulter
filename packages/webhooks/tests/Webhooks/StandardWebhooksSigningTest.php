<?php

declare(strict_types=1);

use Vaulter\Webhooks\WebhookSecret;
use Vaulter\Webhooks\WebhookSigner;

/**
 * The signature must match the Standard Webhooks reference exactly, or no off-the-shelf
 * receiver library can verify a Vaulter delivery.
 *
 * Vector: libraries/php/tests/WebhookTest.php::testSignFunctionWorks in
 * https://github.com/standard-webhooks/standard-webhooks (the same key, id, timestamp
 * and payload appear in the Python, Ruby and Go library tests).
 */
const STANDARD_WEBHOOKS_VECTOR_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
const STANDARD_WEBHOOKS_VECTOR_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
const STANDARD_WEBHOOKS_VECTOR_TIMESTAMP = 1614265330;
const STANDARD_WEBHOOKS_VECTOR_BODY = '{"test": 2432232314}';
const STANDARD_WEBHOOKS_VECTOR_SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

it('produces the official Standard Webhooks signature', function (): void {
    $signature = (new WebhookSigner)->sign(
        STANDARD_WEBHOOKS_VECTOR_ID,
        STANDARD_WEBHOOKS_VECTOR_TIMESTAMP,
        STANDARD_WEBHOOKS_VECTOR_BODY,
        STANDARD_WEBHOOKS_VECTOR_SECRET,
    );

    expect($signature)->toBe(STANDARD_WEBHOOKS_VECTOR_SIGNATURE);
});

it('puts every secret into the header, the current one first', function (): void {
    $other = WebhookSecret::generate();

    $header = (new WebhookSigner)->signatureHeader(
        STANDARD_WEBHOOKS_VECTOR_ID,
        STANDARD_WEBHOOKS_VECTOR_TIMESTAMP,
        STANDARD_WEBHOOKS_VECTOR_BODY,
        [STANDARD_WEBHOOKS_VECTOR_SECRET, $other],
    );

    $parts = explode(' ', $header);

    expect($parts)->toHaveCount(2)
        ->and($parts[0])->toBe(STANDARD_WEBHOOKS_VECTOR_SIGNATURE)
        ->and($parts[1])->toStartWith('v1,')->not->toBe($parts[0]);
});

it('changes the signature when the id, the timestamp or a body byte changes', function (): void {
    $signer = new WebhookSigner;
    $sign = static fn (string $id, int $timestamp, string $body): string => $signer->sign($id, $timestamp, $body, STANDARD_WEBHOOKS_VECTOR_SECRET);

    $base = $sign(STANDARD_WEBHOOKS_VECTOR_ID, STANDARD_WEBHOOKS_VECTOR_TIMESTAMP, STANDARD_WEBHOOKS_VECTOR_BODY);

    expect($sign('msg_other', STANDARD_WEBHOOKS_VECTOR_TIMESTAMP, STANDARD_WEBHOOKS_VECTOR_BODY))->not->toBe($base)
        ->and($sign(STANDARD_WEBHOOKS_VECTOR_ID, STANDARD_WEBHOOKS_VECTOR_TIMESTAMP + 1, STANDARD_WEBHOOKS_VECTOR_BODY))->not->toBe($base)
        ->and($sign(STANDARD_WEBHOOKS_VECTOR_ID, STANDARD_WEBHOOKS_VECTOR_TIMESTAMP, '{"test": 2432232315}'))->not->toBe($base);
});

it('generates whsec_ secrets whose key is 32 random bytes', function (): void {
    $secret = WebhookSecret::generate();

    expect($secret)->toStartWith('whsec_')
        ->and(strlen(WebhookSecret::keyBytes($secret)))->toBe(32)
        ->and(WebhookSecret::generate())->not->toBe($secret);
});

it('refuses a secret that is not whsec_ plus 24 to 64 base64 key bytes without echoing it', function (string $secret): void {
    expect(fn () => WebhookSecret::keyBytes($secret))
        ->toThrow(InvalidArgumentException::class, 'whsec_');

    try {
        WebhookSecret::keyBytes($secret);
    } catch (InvalidArgumentException $exception) {
        // The bare prefix is part of the wording itself; anything longer must not appear.
        expect(strlen($secret) <= strlen(WebhookSecret::PREFIX) || !str_contains($exception->getMessage(), $secret))->toBeTrue();
    }
})->with([
    'no prefix' => ['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'],
    'empty key' => ['whsec_'],
    'not base64' => ['whsec_!!!not base64!!!'],
    'too short' => ['whsec_'.'QUJD'],
    'too long' => ['whsec_'.'QUJD'.'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVphYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5ekFCQ0RFRkdISUpLTE1OT1BRUlNUVVZXWFla'],
]);

it('wraps raw key bytes so the HMAC key stays byte-identical', function (): void {
    $legacy = 'legacy-plaintext-secret-of-24+';

    expect(WebhookSecret::keyBytes(WebhookSecret::fromKeyBytes($legacy)))->toBe($legacy);
});
