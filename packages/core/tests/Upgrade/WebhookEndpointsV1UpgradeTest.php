<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;
use Vaulter\Webhooks\WebhookSecret;

/**
 * 2026_10_10_000003_webhook_endpoints_v1 on a 0.2 database: every stored signing secret
 * becomes whsec_ + base64(old secret), so the HMAC key keeps the exact bytes the endpoint
 * signed with; the rotation and auto-disable columns appear; down() restores the old
 * secrets and drops the columns. Re-running up() changes nothing.
 */
uses(LoadsV02Fixture::class);

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }

    RefreshDatabaseState::$migrated = false;
});

function webhookEndpointsV1Migration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_10_000003_webhook_endpoints_v1.php';
}

it('converts 0.2 secrets to whsec_ with the same key bytes, reversibly and idempotently', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture($strategy);

    $db = app(VaulterDatabase::class)->connection();
    $endpoints = app(DatabaseConfig::class)->table('webhook_endpoints');
    $plain = $db->table($endpoints)->orderBy('id')->pluck('signing_secret', 'id')
        ->map(static fn (string $stored): string => Crypt::decryptString($stored))->all();
    expect($plain)->not->toBeEmpty();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $schema = $db->getSchemaBuilder();
    expect($schema->hasColumns($endpoints, ['previous_signing_secret', 'previous_secret_expires_at', 'failing_since', 'disabled_at', 'disabled_reason']))->toBeTrue();

    $converted = $db->table($endpoints)->orderBy('id')->pluck('signing_secret', 'id')
        ->map(static fn (string $stored): string => Crypt::decryptString($stored))->all();
    foreach ($plain as $id => $secret) {
        expect($converted[$id])->toBe(WebhookSecret::fromKeyBytes($secret))
            ->and(WebhookSecret::keyBytes($converted[$id]))->toBe($secret);
    }
    expect($db->table($endpoints)->whereNotNull('previous_signing_secret')->orWhereNotNull('failing_since')->orWhereNotNull('disabled_reason')->count())->toBe(0);

    // up() again is a no-op: an already converted secret is not wrapped twice.
    webhookEndpointsV1Migration()->up();
    expect($db->table($endpoints)->orderBy('id')->pluck('signing_secret', 'id')->map(static fn (string $stored): string => Crypt::decryptString($stored))->all())->toBe($converted);

    webhookEndpointsV1Migration()->down();
    expect($schema->hasColumn($endpoints, 'previous_signing_secret'))->toBeFalse()
        ->and($schema->hasColumn($endpoints, 'failing_since'))->toBeFalse()
        ->and($db->table($endpoints)->orderBy('id')->pluck('signing_secret', 'id')->map(static fn (string $stored): string => Crypt::decryptString($stored))->all())->toBe($plain);

    webhookEndpointsV1Migration()->up();
    expect($schema->hasColumn($endpoints, 'disabled_reason'))->toBeTrue();
})->with(['bigint', 'ulid']);

it('stops without changing any secret when one cannot be decrypted', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture($strategy);

    $db = app(VaulterDatabase::class)->connection();
    $endpoints = app(DatabaseConfig::class)->table('webhook_endpoints');
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    webhookEndpointsV1Migration()->down();

    $first = $db->table($endpoints)->orderBy('id')->first();
    $db->table($endpoints)->where('id', $first->id)->update(['signing_secret' => 'not-encrypted-with-this-key']);
    $before = $db->table($endpoints)->orderBy('id')->pluck('signing_secret', 'id')->all();

    expect(fn () => webhookEndpointsV1Migration()->up())->toThrow(RuntimeException::class, 'nothing was changed');
    expect($db->table($endpoints)->orderBy('id')->pluck('signing_secret', 'id')->all())->toBe($before);
})->with(['bigint']);
