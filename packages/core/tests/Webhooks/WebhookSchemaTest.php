<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Support\Config;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

/**
 * The reliability-layer schema (§2.11 / D14 / M3b), ported from chatom 1:1: v_outbox,
 * v_webhook_endpoints, v_webhook_deliveries — every column exists, the idempotency and
 * FK constraints hold, and the tables are gated on the 'reliability' feature.
 */
it('creates v_outbox with every §2.11 column', function (): void {
    $table = Config::table('outbox');

    expect(Schema::hasColumns($table, [
        'id', 'event_id', 'panel', 'topic', 'event', 'payload',
        'available_at', 'dispatched_at', 'attempts', 'status',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates v_webhook_endpoints with every §2.11 column', function (): void {
    $table = Config::table('webhook_endpoints');

    expect(Schema::hasColumns($table, [
        'id', 'url', 'events', 'signing_secret', 'is_active',
        'last_triggered_at', 'failure_count', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates v_webhook_deliveries with every §2.11 column', function (): void {
    $table = Config::table('webhook_deliveries');

    expect(Schema::hasColumns($table, [
        'id', 'endpoint_id', 'event', 'payload', 'status', 'attempts',
        'next_retry_at', 'last_error', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('enforces the event_id UNIQUE idempotency key on v_outbox', function (): void {
    WebhookTestHelpers::outbox(['event_id' => 'dup']);

    // SAVEPOINT the duplicate: on PostgreSQL the UNIQUE violation would otherwise abort the
    // enclosing RefreshDatabase transaction (see BlobDedupScopeTest).
    expect(fn () => DB::transaction(fn () => WebhookTestHelpers::outbox(['event_id' => 'dup'])))
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
    $foreignKeys = Schema::getForeignKeys(Config::table('webhook_deliveries'));

    $columns = collect($foreignKeys)
        ->flatMap(static fn (array $fk): array => $fk['columns'])
        ->all();

    expect($columns)->toContain('endpoint_id');
});
