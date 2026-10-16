<?php

declare(strict_types=1);

namespace Vaulter\Schema;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Contracts\IdStrategy;

/** The tables the kernel owns: the transactional outbox, its consumers and deliveries, and the idempotency keys. */
#[Internal]
final class KernelSchema implements ModuleSchema
{
    public function __construct(private readonly SchemaHelpers $helpers = new SchemaHelpers) {}

    /** @return list<string> the entity keys of the tables this schema owns, in dependency order */
    public function entities(): array
    {
        return [
            'outbox',
            'outbox_consumers',
            'outbox_deliveries',
            'idempotency_keys',
        ];
    }

    public function create(string $entity): void
    {
        $ids = $this->helpers->ids();

        $this->helpers->schemaConnection()->create(app(DatabaseConfig::class)->table($entity), function (Blueprint $table) use ($ids, $entity): void {
            match ($entity) {
                'outbox' => $this->defineOutbox(table: $table),
                'outbox_consumers' => $this->defineOutboxConsumers(table: $table),
                'outbox_deliveries' => $this->defineOutboxDeliveries(table: $table),
                'idempotency_keys' => $this->defineIdempotencyKeys(table: $table, ids: $ids),
                default => throw new InvalidArgumentException(sprintf('Unknown kernel entity [%s].', $entity)),
            };
        });
    }

    public function drop(string $entity): void
    {
        $this->helpers->schemaConnection()->dropIfExists(app(DatabaseConfig::class)->table($entity));
    }

    /**
     * v_outbox — the neutral transactional outbox (D29): ONE row per domain event, written in the transaction of
     * the operation whatever modules are active, idempotent by event_id (UNIQUE). outbox_seq is the internal,
     * monotonic order every consumer reconciles by — a bigIncrements independent of the panel id strategy, like
     * event_id. scope_kind/scope_id name what the event is about (a drive, or the tenant) without a drive column;
     * payload is the envelope a consumer receives. The kernel keeps no delivery state here: that lives in
     * v_outbox_deliveries, one row per consumer. The (created_at) index serves reconciliation and retention.
     */
    private function defineOutbox(Blueprint $table): void
    {
        $table->bigIncrements('outbox_seq');
        $table->string('event_id', 64)->unique();
        $this->helpers->tenant(table: $table);
        $table->string('scope_kind', 32);
        $table->string('scope_id', 191)->nullable();
        $table->string('aggregate_type', 32)->nullable();
        $table->string('aggregate_id', 64)->nullable();
        $table->bigInteger('sequence')->nullable();
        $table->string('type', 64);
        $table->boolean('external');
        $table->json('payload');
        $table->timestamp('created_at');

        $table->index('created_at', $table->getTable().'_created_idx');
    }

    /**
     * v_outbox_consumers — one row per outbox consumer the relay ever registered (D29): its delivery class, its
     * state (active, paused while it is not in the sealed registry or switched off, detached once the hold ran
     * out), the seq it was activated at and the seq reconciliation reached, the fenced consumer lease of the
     * reconciler, and the explicit gaps a detach left (JSON list of {from_seq, to_seq, events, at,
     * acknowledged_at}).
     */
    private function defineOutboxConsumers(Blueprint $table): void
    {
        $table->string('consumer_id', 64)->primary();
        $table->string('delivery_class', 16);
        $table->string('state', 16);
        $table->bigInteger('activation_seq');
        $table->bigInteger('reconciled_through_seq');
        $table->timestamp('paused_at')->nullable();
        $table->string('lease_owner', 64)->nullable();
        $table->bigInteger('lease_generation')->default(0);
        $table->timestamp('lease_expires_at')->nullable();
        $table->json('gaps');
        $table->timestamps();
    }

    /**
     * v_outbox_deliveries — the delivery of one event to one consumer (D29), PK(event_id, consumer_id): pending
     * until a relay worker leases it, then done or dead; every completion is fenced by lease_generation, so a
     * worker whose lease was taken over writes nothing. continuation is the consumer's own resume cursor (the
     * webhook fan-out keeps its last endpoint key there). No FK to v_outbox: retention deletes an event only
     * after its rows are terminal, and dead rows live by their own retention.
     */
    private function defineOutboxDeliveries(Blueprint $table): void
    {
        $name = $table->getTable();

        $table->string('event_id', 64);
        $table->string('consumer_id', 64);
        $table->bigInteger('outbox_seq');
        $table->string('state', 16);
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('next_attempt_at');
        $table->string('lease_owner', 64)->nullable();
        $table->bigInteger('lease_generation')->default(0);
        $table->timestamp('lease_expires_at')->nullable();
        $table->string('continuation', 191)->nullable();
        $table->text('last_error')->nullable();
        $table->timestamps();
        $table->timestamp('done_at')->nullable();

        $table->primary(['event_id', 'consumer_id'], $name.'_pkey');
        $table->index(['consumer_id', 'state', 'next_attempt_at'], $name.'_due_idx');
        $table->index(['state', 'lease_expires_at'], $name.'_lease_idx');
        $table->index('outbox_seq', $name.'_seq_idx');
    }

    /**
     * v_idempotency_keys — the claims and receipts of keyed writes (D25, ADR-0029; generic core owner). One row per
     * scope digest: SHA-256 (lowercase hex) of the tagged tuple tenant/scope/actor/operation/key, which is NOT NULL
     * and the only UNIQUE key, so a null tenant or a system actor cannot weaken it; the tuple is kept to refuse a
     * digest collision. The key bytes are stored base64 (opaque, compared exactly). A row is inserted as
     * `claiming` in the transaction of the write and completed by it; `claiming` never commits. The receipt is the
     * codec body of the result, or the problem of a cached refusal; resource_* and auth_scope_* are what a replay
     * authorizes again. expires_at drives vaulter:idempotency:prune.
     */
    private function defineIdempotencyKeys(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);

        $name = $table->getTable();

        $table->char('scope_digest', 64)->unique($name.'_scope_unique');
        $table->string('tenant_kind', 16);
        $table->string('tenant_id', 191)->nullable();
        $table->string('scope_kind', 32);
        $table->string('scope_id', 191);
        $table->string('actor_kind', 16);
        $table->string('actor_id', 191)->nullable();
        $table->string('operation', 64);
        $table->string('idempotency_key', 344);
        $table->char('request_hash', 64);
        $table->string('status', 16);
        $table->string('receipt_kind', 64)->nullable();
        $table->unsignedSmallInteger('codec_version')->nullable();
        $table->json('receipt_body')->nullable();
        $table->unsignedSmallInteger('http_code')->nullable();
        $table->json('receipt_headers')->nullable();
        $table->string('resource_kind', 32)->nullable();
        $table->string('resource_id', 191)->nullable();
        $table->string('auth_scope_kind', 32)->nullable();
        $table->string('auth_scope_id', 191)->nullable();
        $table->timestamp('created_at');
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('expires_at');

        $table->index('expires_at', $name.'_expires_idx');
    }
}
