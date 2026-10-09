<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

/**
 * The reliability-layer schema (§2.11 / D14 / M3b), ported from chatom 1:1: v_outbox,
 * v_webhook_endpoints, v_webhook_deliveries — every column exists, the idempotency and
 * FK constraints hold, and the tables are gated on the 'reliability' feature.
 */
it('creates v_outbox with every §2.11 column', function (): void {
    $table = app(DatabaseConfig::class)->table('outbox');

    expect(Schema::hasColumns($table, [
        'id', 'event_id', 'profile', 'tenant_id', 'drive_id', 'topic', 'event', 'payload',
        'available_at', 'attempts', 'status', 'created_at', 'updated_at',
        'claim_token', 'claim_generation', 'claim_until', 'fanout_cursor',
        'fanned_out_at', 'delivery_count', 'legacy_fanout',
    ]))->toBeTrue()
        ->and(Schema::hasColumn($table, 'dispatched_at'))->toBeFalse();
});

it('creates v_webhook_endpoints with every §2.11 column', function (): void {
    $table = app(DatabaseConfig::class)->table('webhook_endpoints');

    expect(Schema::hasColumns($table, [
        'id', 'url', 'events', 'signing_secret', 'is_active',
        'last_triggered_at', 'failure_count', 'created_at', 'updated_at',
        'tenant_id', 'drive_id',
    ]))->toBeTrue();
});

it('creates v_webhook_deliveries with every §2.11 column', function (): void {
    $table = app(DatabaseConfig::class)->table('webhook_deliveries');

    expect(Schema::hasColumns($table, [
        'id', 'endpoint_id', 'event', 'payload', 'status', 'attempts',
        'next_retry_at', 'claim_token', 'claim_until', 'last_error', 'created_at', 'updated_at',
        'event_id',
    ]))->toBeTrue();
});

it('lets an occurrence reach an endpoint through one delivery row only', function (): void {
    $endpoint = WebhookTestHelpers::endpoint();
    WebhookTestHelpers::delivery($endpoint, ['event_id' => 'evt-pair']);
    WebhookTestHelpers::delivery($endpoint, ['event_id' => null]);
    WebhookTestHelpers::delivery($endpoint, ['event_id' => null]);

    expect(fn () => DB::transaction(fn () => WebhookTestHelpers::delivery($endpoint, ['event_id' => 'evt-pair'])))
        ->toThrow(QueryException::class)
        ->and(WebhookTestHelpers::delivery(WebhookTestHelpers::endpoint(), ['event_id' => 'evt-pair'])->exists)->toBeTrue();
});

it('enforces the event_id UNIQUE idempotency key on v_outbox', function (): void {
    WebhookTestHelpers::outbox(['event_id' => 'dup']);

    // SAVEPOINT the duplicate: on PostgreSQL the UNIQUE violation would otherwise abort the
    // enclosing RefreshDatabase transaction (see BlobDedupScopeTest).
    expect(fn () => DB::transaction(fn (): Outbox => WebhookTestHelpers::outbox(['event_id' => 'dup'])))
        ->toThrow(QueryException::class);
});

it('casts the outbox json + datetime columns', function (): void {
    $row = WebhookTestHelpers::outbox([
        'payload' => ['a' => 1, 'event_id' => 'x'],
    ]);

    $fresh = Outbox::query()->whereKey($row->getKey())->firstOrFail();

    expect($fresh->payload)->toBe(['a' => 1, 'event_id' => 'x'])
        ->and($fresh->available_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->attempts)->toBe(0);
});

it('declares the endpoint_id foreign key on v_webhook_deliveries', function (): void {
    // The cascadeOnDelete is enforced on Postgres/MySQL (the golden-migration suite
    // runs there); on the sqlite base suite we assert the FK is DECLARED on the table
    // so the structural guarantee is pinned without depending on PRAGMA enforcement.
    $foreignKeys = Schema::getForeignKeys(app(DatabaseConfig::class)->table('webhook_deliveries'));

    $columns = collect($foreignKeys)
        ->flatMap(static fn (array $fk): array => $fk['columns'])
        ->all();

    expect($columns)->toContain('endpoint_id');
});

it('upgrades dispatched rows and repeated delivery pairs on the suite driver and rolls back', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_10_10_000002_webhook_fanout.php';
    $outbox = app(DatabaseConfig::class)->table('outbox');
    $deliveries = app(DatabaseConfig::class)->table('webhook_deliveries');
    $endpoints = app(DatabaseConfig::class)->table('webhook_endpoints');
    $endpoint = WebhookTestHelpers::endpoint();

    $migration->down();
    expect(Schema::hasColumn($outbox, 'dispatched_at'))->toBeTrue()
        ->and(Schema::hasColumn($deliveries, 'event_id'))->toBeFalse()
        ->and(Schema::hasColumn($endpoints, 'drive_id'))->toBeFalse();

    $settledAt = Carbon::parse('2026-01-01 01:09:00');
    DB::table($outbox)->insert([
        ['event_id' => 'old-1', 'profile' => 'default', 'event' => 'folder.created', 'payload' => '{}', 'available_at' => $settledAt, 'dispatched_at' => $settledAt, 'attempts' => 0, 'status' => 'dispatched', 'created_at' => $settledAt, 'updated_at' => $settledAt],
        ['event_id' => 'old-2', 'profile' => 'default', 'event' => 'folder.created', 'payload' => '{}', 'available_at' => $settledAt, 'dispatched_at' => null, 'attempts' => 0, 'status' => 'pending', 'created_at' => $settledAt, 'updated_at' => $settledAt],
    ]);
    foreach (['2026-01-01 01:00:00', '2026-01-01 02:00:00'] as $createdAt) {
        DB::table($deliveries)->insert(['endpoint_id' => $endpoint->getKey(), 'event' => 'folder.created', 'payload' => '{"event_id":"old-1"}', 'status' => 'delivered', 'attempts' => 1, 'created_at' => $createdAt, 'updated_at' => $createdAt]);
    }
    DB::table($deliveries)->insert(['endpoint_id' => $endpoint->getKey(), 'event' => 'folder.created', 'payload' => '{"id":"envelope-1"}', 'status' => 'pending', 'attempts' => 0, 'created_at' => $settledAt, 'updated_at' => $settledAt]);

    $migration->up();

    $old = Outbox::query()->where('event_id', 'old-1')->sole();
    expect($old->status)->toBe('completed')
        ->and($old->legacy_fanout)->toBeTrue()
        ->and($old->fanned_out_at?->toDateTimeString())->toBe('2026-01-01 01:09:00')
        ->and(Outbox::query()->where('event_id', 'old-2')->sole()->status)->toBe('pending')
        ->and(DB::table($deliveries)->orderBy('created_at')->orderBy('id')->pluck('event_id')->all())->toBe(['old-1', 'envelope-1', null])
        ->and(Schema::hasColumn($outbox, 'dispatched_at'))->toBeFalse()
        ->and(Schema::hasColumns($endpoints, ['tenant_id', 'drive_id']))->toBeTrue();

    $migration->down();
    expect(DB::table($outbox)->where('event_id', 'old-1')->value('status'))->toBe('dispatched');
    $migration->up();
    expect(Outbox::query()->where('event_id', 'old-1')->sole()->status)->toBe('completed');
});
