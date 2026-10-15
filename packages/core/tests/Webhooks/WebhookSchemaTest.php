<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

/**
 * The reliability-layer schema: the neutral outbox of core (D29) — v_outbox, v_outbox_consumers,
 * v_outbox_deliveries — and the webhook tables v_webhook_endpoints, v_webhook_deliveries:
 * every column exists and the idempotency and FK constraints hold.
 */
it('creates the neutral v_outbox without delivery state', function (): void {
    $table = app(DatabaseConfig::class)->table('outbox');

    expect(Schema::getColumnListing($table))->toEqualCanonicalizing([
        'outbox_seq', 'event_id', 'tenant_id', 'scope_kind', 'scope_id', 'aggregate_type', 'aggregate_id',
        'sequence', 'type', 'external', 'payload', 'created_at',
    ]);
});

it('creates v_outbox_consumers and v_outbox_deliveries', function (): void {
    expect(Schema::getColumnListing(app(DatabaseConfig::class)->table('outbox_consumers')))->toEqualCanonicalizing([
        'consumer_id', 'delivery_class', 'state', 'activation_seq', 'reconciled_through_seq', 'paused_at',
        'lease_owner', 'lease_generation', 'lease_expires_at', 'gaps', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing(app(DatabaseConfig::class)->table('outbox_deliveries')))->toEqualCanonicalizing([
        'event_id', 'consumer_id', 'outbox_seq', 'state', 'attempts', 'next_attempt_at', 'lease_owner',
        'lease_generation', 'lease_expires_at', 'continuation', 'last_error', 'created_at', 'updated_at', 'done_at',
    ]);
});

it('creates v_webhook_endpoints with every §2.11 column', function (): void {
    $table = app(DatabaseConfig::class)->table('webhook_endpoints');

    expect(Schema::hasColumns($table, [
        'id', 'url', 'events', 'signing_secret', 'is_active',
        'last_triggered_at', 'failure_count', 'created_at', 'updated_at',
        'tenant_id', 'drive_id',
        'previous_signing_secret', 'previous_secret_expires_at', 'failing_since', 'disabled_at', 'disabled_reason',
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
    expect(fn () => DB::transaction(fn (): OutboxEvent => WebhookTestHelpers::outbox(['event_id' => 'dup'], consumer: null)))
        ->toThrow(QueryException::class);
});

it('casts the outbox json + datetime columns', function (): void {
    $row = WebhookTestHelpers::outbox([
        'payload' => ['a' => 1, 'event_id' => 'x'],
    ]);

    $fresh = OutboxEvent::query()->whereKey($row->getKey())->firstOrFail();

    expect($fresh->payload)->toBe(['a' => 1, 'event_id' => 'x'])
        ->and($fresh->created_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->external)->toBeTrue()
        ->and($fresh->outbox_seq)->toBeInt();
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
