<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Schema;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\SchemaHelpers;

/** The tables the webhooks module owns: subscriber endpoints and delivery attempts. */
#[Internal]
final class WebhooksSchema implements ModuleSchema
{
    public function __construct(private readonly SchemaHelpers $helpers) {}

    /** @return list<string> the entity keys of the tables this schema owns, in dependency order */
    public function entities(): array
    {
        return [
            'webhook_endpoints',
            'webhook_deliveries',
        ];
    }

    public function create(string $entity): void
    {
        $ids = $this->helpers->ids();

        $this->helpers->schemaConnection()->create($this->helpers->table($entity), function (Blueprint $table) use ($ids, $entity): void {
            match ($entity) {
                'webhook_endpoints' => $this->defineWebhookEndpoints(table: $table, ids: $ids),
                'webhook_deliveries' => $this->defineWebhookDeliveries(table: $table, ids: $ids),
                default => throw new InvalidArgumentException(sprintf('Unknown webhooks entity [%s].', $entity)),
            };
        });
    }

    public function drop(string $entity): void
    {
        $this->helpers->schemaConnection()->dropIfExists($this->helpers->table($entity));
    }

    /**
     * v_webhook_endpoints — the subscriber endpoints,
     * ported from chatom 1:1. Gated on 'reliability'. events is the JSON subscription
     * list (matched in SQL with whereJsonContains); signing_secret signs the
     * deliveries (Standard Webhooks, HMAC-SHA256); failing_since is the auto-disable
     * clock, previous_signing_secret the rotation overlap. tenant_id
     * and the optional drive_id narrow which events the endpoint receives.
     */
    private function defineWebhookEndpoints(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);

        $table->string('url');
        $table->json('events');
        // text, not string: the signing_secret is encrypted at rest and
        // the ciphertext is longer than the 64-char plaintext.
        $table->text('signing_secret');
        // The secret before the last rotation, valid for signing until its expiry.
        $table->text('previous_signing_secret')->nullable();
        $table->timestamp('previous_secret_expires_at')->nullable();
        $table->boolean('is_active')->default(true)->index();
        $table->timestamp('last_triggered_at')->nullable();
        $table->unsignedInteger('failure_count')->default(0);
        // Start of the current unbroken run of failed attempts; the auto-disable clock.
        $table->timestamp('failing_since')->nullable();
        $table->timestamp('disabled_at')->nullable();
        // 'failing' (auto-disabled) or 'manual'.
        $table->string('disabled_reason', 32)->nullable();
        $table->timestamps();
        $this->helpers->tenant(table: $table);
        $ids->reference(table: $table, column: 'drive_id', nullable: true);

        $name = $table->getTable();

        // The relay's eligible-endpoint scan filters by both.
        $table->index(['is_active', 'tenant_id'], $name.'_active_tenant_idx');
        $table->foreign('drive_id')
            ->references('id')
            ->on($this->helpers->table('drives'))
            ->cascadeOnDelete();
    }

    /**
     * v_webhook_deliveries — a single delivery attempt with retry bookkeeping (master
     * plan), ported from chatom 1:1. Gated on 'reliability'.
     * endpoint_id is an explicit FK → v_webhook_endpoints (cascade on delete). The
     * (status, next_retry_at) index is the due-retry sweep cursor; (status, updated_at)
     * backs the retention prune of terminal (delivered|dead) rows. (event_id,
     * endpoint_id) is unique, so an occurrence reaches an endpoint through one row
     * however often the relay retries its fan-out.
     */
    private function defineWebhookDeliveries(Blueprint $table, IdStrategy $ids): void
    {
        $ids->key(table: $table);
        $ids->reference(table: $table, column: 'endpoint_id', nullable: false);

        $table->string('event');
        $table->json('payload');
        $table->string('status');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('next_retry_at')->nullable();
        $table->string('claim_token', 32)->nullable();
        $table->timestamp('claim_until')->nullable();
        $table->text('last_error')->nullable();
        $table->timestamps();
        $table->string('event_id', 64)->nullable();

        $name = $table->getTable();

        $table->unique(['event_id', 'endpoint_id'], $name.'_event_endpoint_unique');
        $table->index(['status', 'next_retry_at'], $name.'_retry_idx');
        // The retention sweep (vaulter:webhooks:prune) deletes terminal rows by
        // (status in delivered|dead, updated_at <= threshold) — its own scan cursor.
        $table->index(['status', 'updated_at'], $name.'_prune_idx');

        $table->foreign('endpoint_id')
            ->references('id')
            ->on($this->helpers->table('webhook_endpoints'))
            ->cascadeOnDelete();
    }
}
