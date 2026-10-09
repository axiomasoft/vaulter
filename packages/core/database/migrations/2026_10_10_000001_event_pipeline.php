<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Schema\HostKeyColumns;

/**
 * The event pipeline records every occurrence once in each sink (PLAN2.D22, D58):
 *  - v_activity.event_id becomes UNIQUE, so recording an occurrence again inserts
 *    nothing. Rows written before it carry a null event_id and never collide.
 *  - v_outbox gets tenant_id, the scope endpoints are matched against.
 *
 * Existing duplicate event ids stop the migration before any change; the message
 * names how many there are and the query that lists them. Nothing is merged or
 * deleted: which row of a duplicate is the true one is the operator's call.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $activity = app(DatabaseConfig::class)->table('activity');
        $outbox = app(DatabaseConfig::class)->table('outbox');

        if ($schema->hasTable($activity) && $schema->hasColumn($activity, 'event_id') && !$schema->hasIndex($activity, $activity.'_event_id_unique')) {
            $duplicates = $db->query()->fromSub(
                $db->table($activity)->select('event_id')->whereNotNull('event_id')->groupBy('event_id')->havingRaw('count(*) > 1'),
                'duplicates',
            )->count();
            if ($duplicates > 0) {
                throw new UpgradePreflightException([
                    sprintf('%d event ids occur more than once in %s', $duplicates, $activity),
                    sprintf('list them with: select event_id, count(*) from %s where event_id is not null group by event_id having count(*) > 1', $activity),
                    'no table was changed; remove or re-key the duplicate rows, then migrate again',
                ]);
            }

            $schema->table($activity, static fn (Blueprint $table) => $table->unique('event_id'));
        }

        // The unique index serves every lookup the plain one did. On a fresh install the
        // plain one comes from 2026_10_01_000009, which runs before this migration.
        if ($schema->hasTable($activity) && $schema->hasIndex($activity, $activity.'_event_id_index')) {
            $schema->table($activity, static fn (Blueprint $table) => $table->dropIndex($activity.'_event_id_index'));
        }

        if ($schema->hasTable($outbox) && !$schema->hasColumn($outbox, 'tenant_id')) {
            $schema->table($outbox, static fn (Blueprint $table) => HostKeyColumns::add(table: $table, column: 'tenant_id', nullable: true, index: true));
        }
    }

    public function down(): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        $activity = app(DatabaseConfig::class)->table('activity');
        $outbox = app(DatabaseConfig::class)->table('outbox');

        if ($schema->hasTable($activity) && $schema->hasIndex($activity, $activity.'_event_id_unique')) {
            $schema->table($activity, static fn (Blueprint $table) => $table->dropUnique($activity.'_event_id_unique'));
        }
        if ($schema->hasTable($activity) && $schema->hasColumn($activity, 'event_id') && !$schema->hasIndex($activity, $activity.'_event_id_index')) {
            $schema->table($activity, static fn (Blueprint $table) => $table->index('event_id'));
        }

        if ($schema->hasTable($outbox) && $schema->hasColumn($outbox, 'tenant_id')) {
            if ($schema->hasIndex($outbox, $outbox.'_tenant_id_index')) {
                $schema->table($outbox, static fn (Blueprint $table) => $table->dropIndex($outbox.'_tenant_id_index'));
            }
            $schema->table($outbox, static fn (Blueprint $table) => $table->dropColumn('tenant_id'));
        }
    }
};
