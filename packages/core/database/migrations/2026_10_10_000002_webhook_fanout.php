<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Symfony\Component\Console\Output\ConsoleOutput;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\IdStrategyResolver;

/**
 * The webhook fan-out becomes recoverable (PLAN2.D29, D50):
 *  - v_outbox gets the relay lease (claim_token, claim_generation, claim_until), the
 *    fan-out checkpoint (fanout_cursor, delivery_count) and the completion marker
 *    (fanned_out_at). Statuses are pending | completed. Rows the old relay flipped to
 *    'dispatched' become completed + legacy_fanout: the old relay committed that
 *    status before writing deliveries, so whether every endpoint got one cannot be
 *    told from the data (vaulter:webhooks:legacy-fanout lists and replays them).
 *  - v_webhook_deliveries gets event_id, back-filled from the payload, and
 *    UNIQUE(event_id, endpoint_id).
 *  - v_webhook_endpoints gets tenant_id and an optional drive_id filter. Existing
 *    endpoints keep tenant_id null.
 */
return new class extends Migration
{
    private const int CHUNK = 500;

    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();

        $this->upgradeOutbox($db);
        $this->upgradeDeliveries($db);
        $this->upgradeEndpoints($db);
    }

    /**
     * Rolling back maps completed rows to 'dispatched' again. A row still pending
     * with a fan-out checkpoint loses it, so the old relay would fan it out from
     * the start; it creates no duplicate only because none of its deliveries exist
     * twice (the old relay does not check).
     */
    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $outbox = app(DatabaseConfig::class)->table('outbox');
        $deliveries = app(DatabaseConfig::class)->table('webhook_deliveries');
        $endpoints = app(DatabaseConfig::class)->table('webhook_endpoints');

        if ($schema->hasTable($endpoints)) {
            // By column: SQLite names no foreign key (Laravel rebuilds the table there).
            foreach ($schema->getForeignKeys($endpoints) as $key) {
                if ($key['columns'] === ['drive_id']) {
                    $schema->table($endpoints, static fn (Blueprint $table) => $table->dropForeign(['drive_id']));
                }
            }
            $this->dropIndexes($db, $endpoints, [$endpoints.'_active_tenant_idx', $endpoints.'_drive_id_index', $endpoints.'_tenant_id_index']);
            $this->dropColumns($db, $endpoints, ['drive_id', 'tenant_id']);
        }

        if ($schema->hasTable($deliveries)) {
            $this->dropIndexes($db, $deliveries, [$deliveries.'_event_endpoint_unique']);
            $this->dropColumns($db, $deliveries, ['event_id']);
        }

        if ($schema->hasTable($outbox)) {
            if (!$schema->hasColumn($outbox, 'dispatched_at')) {
                $schema->table($outbox, static fn (Blueprint $table) => $table->timestamp('dispatched_at')->nullable());
            }
            if ($schema->hasColumn($outbox, 'fanned_out_at')) {
                $db->table($outbox)->where('status', 'completed')->update([
                    'status' => 'dispatched',
                    'dispatched_at' => $db->raw('fanned_out_at'),
                ]);
            }
            $this->dropIndexes($db, $outbox, [$outbox.'_prune_idx']);
            $this->dropColumns($db, $outbox, ['claim_token', 'claim_generation', 'claim_until', 'fanout_cursor', 'fanned_out_at', 'delivery_count', 'legacy_fanout']);
            $schema->table($outbox, static fn (Blueprint $table) => $table->index(['status', 'dispatched_at'], $outbox.'_prune_idx'));
        }
    }

    private function upgradeOutbox(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $outbox = app(DatabaseConfig::class)->table('outbox');
        if (!$schema->hasTable($outbox)) {
            return;
        }

        foreach (['claim_token', 'claim_generation', 'claim_until', 'fanout_cursor', 'fanned_out_at', 'delivery_count', 'legacy_fanout'] as $column) {
            if ($schema->hasColumn($outbox, $column)) {
                continue;
            }

            $schema->table($outbox, static function (Blueprint $table) use ($column): void {
                match ($column) {
                    'claim_token' => $table->string($column, 32)->nullable(),
                    'claim_generation' => $table->bigInteger($column)->default(0),
                    'claim_until', 'fanned_out_at' => $table->timestamp($column)->nullable(),
                    'fanout_cursor' => $table->string($column, 64)->nullable(),
                    'delivery_count' => $table->unsignedInteger($column)->default(0),
                    'legacy_fanout' => $table->boolean($column)->default(false),
                };
            });
        }

        if ($schema->hasColumn($outbox, 'dispatched_at')) {
            $db->table($outbox)->where('status', 'dispatched')->update([
                'status' => 'completed',
                'legacy_fanout' => true,
                'fanned_out_at' => $db->raw('coalesce(dispatched_at, updated_at, created_at)'),
            ]);
            // A legacy row with no timestamp at all would otherwise never age into the prune window.
            $db->table($outbox)->where('status', 'completed')->whereNull('fanned_out_at')->update(['fanned_out_at' => now()]);

            $this->dropIndexes($db, $outbox, [$outbox.'_prune_idx']);
            $this->dropColumns($db, $outbox, ['dispatched_at']);
        }

        if (!$schema->hasIndex($outbox, $outbox.'_prune_idx')) {
            $schema->table($outbox, static fn (Blueprint $table) => $table->index(['status', 'fanned_out_at'], $outbox.'_prune_idx'));
        }
    }

    private function upgradeDeliveries(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $deliveries = app(DatabaseConfig::class)->table('webhook_deliveries');
        if (!$schema->hasTable($deliveries) || $schema->hasIndex($deliveries, $deliveries.'_event_endpoint_unique')) {
            return;
        }

        if (!$schema->hasColumn($deliveries, 'event_id')) {
            $schema->table($deliveries, static fn (Blueprint $table) => $table->string('event_id', 64)->nullable());
        }

        // 0.2 payloads carry event_id; envelopes written since carry the same id as id.
        $db->table($deliveries)->select(['id', 'payload'])->whereNull('event_id')
            ->chunkById(self::CHUNK, static function ($rows) use ($db, $deliveries): void {
                foreach ($rows as $row) {
                    $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;
                    $eventId = is_array($payload) ? ($payload['event_id'] ?? $payload['id'] ?? null) : null;
                    if (is_string($eventId) && $eventId !== '' && strlen($eventId) <= 64) {
                        $db->table($deliveries)->where('id', $row->id)->update(['event_id' => $eventId]);
                    }
                }
            });

        // The old relay flipped the outbox row before fanning out, so a pair should
        // never repeat. If one does, the earliest delivery keeps the id; the others
        // stay as history without it and are never delivered again because of it.
        // Each round resolves the pairs it reads, so the next round starts from the top.
        $duplicates = 0;
        do {
            $pairs = $db->table($deliveries)->select(['event_id', 'endpoint_id'])->whereNotNull('event_id')
                ->groupBy(['event_id', 'endpoint_id'])->havingRaw('count(*) > 1')
                ->limit(self::CHUNK)->get();
            foreach ($pairs as $pair) {
                $pairRows = static fn () => $db->table($deliveries)->where('event_id', $pair->event_id)->where('endpoint_id', $pair->endpoint_id);
                $keeper = $pairRows()->orderBy('created_at')->orderBy('id')->value('id');
                $duplicates += $pairRows()->where('id', '<>', $keeper)->update(['event_id' => null]);
            }
        } while ($pairs->count() === self::CHUNK);
        if ($duplicates > 0 && app()->runningInConsole()) {
            (new ConsoleOutput)->writeln(sprintf('<comment>%s: %d duplicate deliveries of an (event_id, endpoint_id) pair kept without event_id.</comment>', $deliveries, $duplicates));
        }

        $schema->table($deliveries, static fn (Blueprint $table) => $table->unique(['event_id', 'endpoint_id'], $deliveries.'_event_endpoint_unique'));
    }

    private function upgradeEndpoints(Connection $db): void
    {
        $schema = $db->getSchemaBuilder();
        $endpoints = app(DatabaseConfig::class)->table('webhook_endpoints');
        if (!$schema->hasTable($endpoints)) {
            return;
        }

        if (!$schema->hasColumn($endpoints, 'tenant_id')) {
            $schema->table($endpoints, static fn (Blueprint $table) => HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: true, index: true));
        }

        if (!$schema->hasColumn($endpoints, 'drive_id')) {
            $ids = app(IdStrategyResolver::class)->resolve();
            $drives = app(DatabaseConfig::class)->table('drives');
            $sqlite = $db->getDriverName() === 'sqlite';
            $schema->table($endpoints, static function (Blueprint $table) use ($ids, $drives, $sqlite): void {
                $ids->reference(table: $table, column: 'drive_id', nullable: true);
                if (!$sqlite) {
                    $table->foreign('drive_id')->references('id')->on($drives)->cascadeOnDelete();
                }
            });
        }

        if (!$schema->hasIndex($endpoints, $endpoints.'_active_tenant_idx')) {
            $schema->table($endpoints, static fn (Blueprint $table) => $table->index(['is_active', 'tenant_id'], $endpoints.'_active_tenant_idx'));
        }
    }

    /** @param list<string> $names */
    private function dropIndexes(Connection $db, string $table, array $names): void
    {
        $schema = $db->getSchemaBuilder();
        foreach ($schema->getIndexes($table) as $index) {
            if (in_array($index['name'], $names, true)) {
                $schema->table($table, static fn (Blueprint $blueprint) => $index['unique'] ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']));
            }
        }
    }

    /** @param list<string> $columns */
    private function dropColumns(Connection $db, string $table, array $columns): void
    {
        $schema = $db->getSchemaBuilder();
        foreach ($columns as $column) {
            if (!$schema->hasColumn($table, $column)) {
                continue;
            }

            // An index over the column would block the drop on SQLite.
            foreach ($schema->getIndexes($table) as $index) {
                if (!$index['primary'] && in_array($column, $index['columns'], true)) {
                    $schema->table($table, static fn (Blueprint $blueprint) => $index['unique'] ? $blueprint->dropUnique($index['name']) : $blueprint->dropIndex($index['name']));
                }
            }

            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
        }
    }
};
