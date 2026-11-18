<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Webhooks\SigningSecretEncryptor;
use Vaulter\Webhooks\Testing\WebhookTestHelpers;
use Vaulter\Webhooks\WebhookSecret;

/**
 * The webhook signing_secret is encrypted at rest: a DB
 * dump/replica leak must not hand an attacker the HMAC keys. Laravel's `encrypted`
 * cast is transparent (WebhookSigner sees plaintext), and the one-shot encryptor
 * migrates legacy plaintext rows idempotently.
 */
it('stores the signing_secret as ciphertext and reads it back as plaintext, F-P1.7-02', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['signing_secret' => 'super-secret-key']);

    $raw = DB::table(app(DatabaseOptions::class)->table('webhook_endpoints'))
        ->where('id', $endpoint->getKey())
        ->value('signing_secret');

    // The column holds ciphertext, not the plaintext key…
    expect($raw)->toBeString()
        ->and($raw)->not->toBe('super-secret-key')
        ->and(Crypt::decryptString((string) $raw))->toBe('super-secret-key');

    // …and the model attribute is transparently decrypted.
    expect($endpoint->fresh()?->signing_secret)->toBe('super-secret-key');
});

it('encrypts a legacy plaintext secret in place, idempotently, F-P1.7-02', function (): void {
    $table = app(DatabaseOptions::class)->table('webhook_endpoints');

    // Seed a legacy plaintext row through the query builder (bypassing the cast).
    DB::table($table)->insert([
        'url' => 'https://legacy.test/hook',
        'events' => json_encode(['folder.created']),
        'signing_secret' => 'legacy-plaintext',
        'is_active' => true,
        'failure_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $encryptor = app(SigningSecretEncryptor::class);

    // First run encrypts the one plaintext row…
    expect($encryptor->encryptPlaintext($table))->toBe(1);

    $first = DB::table($table)->where('url', 'https://legacy.test/hook')->value('signing_secret');
    expect(Crypt::decryptString((string) $first))->toBe('legacy-plaintext');

    // …the second run is a no-op (already encrypted), leaving the value untouched.
    expect($encryptor->encryptPlaintext($table))->toBe(0);

    $second = DB::table($table)->where('url', 'https://legacy.test/hook')->value('signing_secret');
    expect($second)->toBe($first);
});

it('converts a pre-1.0 secret to whsec_ keeping the key bytes, idempotently and reversibly', function (): void {
    $table = app(DatabaseOptions::class)->table('webhook_endpoints');
    $legacy = 'legacy-secret-with-24-or-more-bytes';
    $native = WebhookSecret::generate();
    foreach (['https://legacy.test/a' => $legacy, 'https://native.test/b' => $native] as $url => $secret) {
        DB::table($table)->insert([
            'url' => $url, 'events' => json_encode(['folder.created']), 'signing_secret' => Crypt::encryptString($secret),
            'is_active' => true, 'failure_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $stored = static fn (string $url): string => Crypt::decryptString((string) DB::table($table)->where('url', $url)->value('signing_secret'));
    $encryptor = app(SigningSecretEncryptor::class);

    // Only the legacy row changes; the native whsec_ secret is left alone.
    expect($encryptor->convertToWebhookSecrets($table))->toBe(1)
        ->and($stored('https://legacy.test/a'))->toBe(WebhookSecret::fromKeyBytes($legacy))
        ->and(WebhookSecret::keyBytes($stored('https://legacy.test/a')))->toBe($legacy)
        ->and($stored('https://native.test/b'))->toBe($native)
        ->and($encryptor->convertToWebhookSecrets($table))->toBe(0);

    // Rollback restores the old plaintext; a natively generated secret yields its key bytes.
    expect($encryptor->revertFromWebhookSecrets($table))->toBe(2)
        ->and($stored('https://legacy.test/a'))->toBe($legacy)
        ->and($stored('https://native.test/b'))->toBe(WebhookSecret::keyBytes($native))
        ->and($encryptor->revertFromWebhookSecrets($table))->toBe(0);
});

it('stops the conversion on a legacy secret that cannot be a Standard Webhooks key', function (): void {
    $table = app(DatabaseOptions::class)->table('webhook_endpoints');
    DB::table($table)->insert([
        'url' => 'https://short.test/a', 'events' => json_encode(['folder.created']), 'signing_secret' => Crypt::encryptString('too-short'),
        'is_active' => true, 'failure_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => app(SigningSecretEncryptor::class)->convertToWebhookSecrets($table))
        ->toThrow(RuntimeException::class, 'outside the 24 to 64 bytes');
});

it('stops the whole conversion when a secret cannot be decrypted', function (): void {
    $table = app(DatabaseOptions::class)->table('webhook_endpoints');
    $good = 'legacy-secret-with-24-or-more-bytes';
    foreach ([['https://good.test/a', Crypt::encryptString($good)], ['https://bad.test/b', 'not-encrypted-with-this-key']] as [$url, $value]) {
        DB::table($table)->insert([
            'url' => $url, 'events' => json_encode(['folder.created']), 'signing_secret' => $value,
            'is_active' => true, 'failure_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(fn () => app(SigningSecretEncryptor::class)->convertToWebhookSecrets($table))
        ->toThrow(RuntimeException::class, 'nothing was changed');

    // All or nothing: the readable row was not converted either.
    expect(Crypt::decryptString((string) DB::table($table)->where('url', 'https://good.test/a')->value('signing_secret')))->toBe($good);
});
