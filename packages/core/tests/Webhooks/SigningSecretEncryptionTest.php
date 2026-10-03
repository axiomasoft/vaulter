<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Vaulter\Support\Config;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\Webhooks\SigningSecretEncryptor;

/**
 * The webhook signing_secret is encrypted at rest (§2.11 / F-P1.7-02): a DB
 * dump/replica leak must not hand an attacker the HMAC keys. Laravel's `encrypted`
 * cast is transparent (WebhookSigner sees plaintext), and the one-shot encryptor
 * migrates legacy plaintext rows idempotently.
 */
it('stores the signing_secret as ciphertext and reads it back as plaintext, F-P1.7-02', function (): void {
    $endpoint = WebhookTestHelpers::endpoint(['signing_secret' => 'super-secret-key']);

    $raw = DB::table(Config::table('webhook_endpoints'))
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
    $table = Config::table('webhook_endpoints');

    // Seed a legacy plaintext row through the query builder (bypassing the cast).
    DB::table($table)->insert([
        'url' => 'https://legacy.test/hook',
        'events' => json_encode(['node.created']),
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
