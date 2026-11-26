<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Schema;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;

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

    /** @return list<KeyColumnExpectation> the key columns of the module's tables and the kind each must have: `HK` host key, `REF` entity id, `STRING` of a length */
    public function keyColumns(): array
    {
        return [
            new KeyColumnExpectation('webhook_endpoints', 'tenant_id', 'HK'),
            new KeyColumnExpectation('webhook_endpoints', 'drive_id', 'REF'),
            new KeyColumnExpectation('webhook_deliveries', 'endpoint_id', 'REF'),
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
        // The endpoint's own version: update, deactivate, secret rotation and deletion are conditional on it
        // (If-Match); each raises it with UPDATE … WHERE version = ?.
        $this->helpers->counterColumn(table: $table, column: 'version');
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
     * backs the retention prune of terminal (delivered|dead) rows; (endpoint_id,
     * created_at, id) serves an endpoint's delivery history in page order. (event_id,
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
        // An endpoint's delivery history, newest first (the admin API's deliveries page).
        // The feed index starts with endpoint_id and then nullable created_at; declare the
        // foreign-key index first so MySQL 9.7 enforces the key (see SchemaHelpers::foreignKeyIndex()).
        $this->helpers->foreignKeyIndex(table: $table, column: 'endpoint_id');
        $table->index(['endpoint_id', 'created_at', 'id'], $name.'_endpoint_feed_idx');

        $table->foreign('endpoint_id')
            ->references('id')
            ->on($this->helpers->table('webhook_endpoints'))
            ->cascadeOnDelete();
    }

    /**
     * The delivery-history index on a table created before it was part of the definition. Public so the upgrade
     * migration adds the same index; a no-op when the table is missing or already has it.
     */
    public function addDeliveriesEndpointFeedIndex(): void
    {
        $schema = $this->helpers->schemaConnection();
        $table = $this->helpers->table('webhook_deliveries');

        if (!$schema->hasTable($table) || $schema->hasIndex($table, $table.'_endpoint_feed_idx')) {
            return;
        }

        $schema->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->index(['endpoint_id', 'created_at', 'id'], $table.'_endpoint_feed_idx');
        });
    }

    public function dropDeliveriesEndpointFeedIndex(): void
    {
        $schema = $this->helpers->schemaConnection();
        $table = $this->helpers->table('webhook_deliveries');

        if (!$schema->hasTable($table) || !$schema->hasIndex($table, $table.'_endpoint_feed_idx')) {
            return;
        }

        $schema->table($table, static function (Blueprint $blueprint) use ($table): void {
            $blueprint->dropIndex($table.'_endpoint_feed_idx');
        });
    }

    /**
     * The MySQL foreign-key index on a table created before it was part of the definition. Public so the upgrade
     * migration adds the same index; a no-op when the table is missing, the index already exists, or the engine
     * is not MySQL. Rebuilds the feed index around it so InnoDB backs the key with the simple index, not the
     * composite whose created_at part is nullable.
     */
    public function addDeliveriesEndpointForeignKeyIndex(): void
    {
        if ($this->helpers->dbConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $schema = $this->helpers->schemaConnection();
        $table = $this->helpers->table('webhook_deliveries');
        $fkIndex = $table.'_endpoint_id_fk_idx';

        if (!$schema->hasTable($table)) {
            return;
        }

        $db = $this->helpers->dbConnection();
        $grammar = $db->getQueryGrammar();
        $feed = $table.'_endpoint_feed_idx';
        $foreign = $table.'_endpoint_id_foreign';
        $endpoints = $this->helpers->table('webhook_endpoints');

        if (!$schema->hasIndex($table, $fkIndex)) {
            // Switch the supporting index in one atomic MySQL DDL statement. The FK
            // stays installed, including while other sessions are writing deliveries.
            $changes = 'ADD INDEX '.$grammar->wrap($fkIndex).' (endpoint_id)';
            if ($schema->hasIndex($table, $feed)) {
                $changes .= ', DROP INDEX '.$grammar->wrap($feed);
            }
            $db->statement('ALTER TABLE '.$grammar->wrapTable($table).' '.$changes);
        }
        $this->addDeliveriesEndpointFeedIndex();

        // Old SQL-layer checks could have admitted orphan deliveries. Preserve them
        // for operator repair and leave the migration pending until integrity holds.
        $orphan = $db->table($table.' as delivery')
            ->leftJoin($endpoints.' as endpoint', 'endpoint.id', '=', 'delivery.endpoint_id')
            ->whereNull('endpoint.id')
            ->value('delivery.id');
        if (is_string($orphan) || is_int($orphan)) {
            throw new \RuntimeException("Delivery {$orphan} references a missing endpoint. Repair it and retry the migration.");
        }

        if (!$this->helpers->hasForeignKey($table, $foreign)) {
            $schema->table($table, static function (Blueprint $blueprint) use ($foreign, $endpoints): void {
                $blueprint->foreign('endpoint_id', $foreign)->references('id')->on($endpoints)->cascadeOnDelete();
            });
        }
    }

    public function dropDeliveriesEndpointForeignKeyIndex(): void
    {
        if ($this->helpers->dbConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $schema = $this->helpers->schemaConnection();
        $table = $this->helpers->table('webhook_deliveries');
        $fkIndex = $table.'_endpoint_id_fk_idx';

        if (!$schema->hasTable($table) || !$schema->hasIndex($table, $fkIndex)) {
            return;
        }

        $this->addDeliveriesEndpointFeedIndex();
        $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropIndex($fkIndex));
    }
}
