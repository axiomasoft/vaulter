<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\IdStrategyResolver;

return new class extends Migration
{
    private const array EVENTS = [
        'storage.created' => 'drive.created',
        'node.created' => 'folder.created',
        'version.created' => 'file.revision_added',
        'node.force_deleted' => 'node.purged',
        'upload_session.initiated' => 'upload.initiated',
        'upload_session.aborted' => 'upload.aborted',
        'upload_session.expired' => 'upload.expired',
    ];

    public function up(): void
    {
        $this->convertEvents(self::EVENTS);
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        $activity = app(DatabaseConfig::class)->table('activity');
        if (!$schema->hasColumn($activity, 'event_id')) {
            $schema->table($activity, fn (Blueprint $table) => $table->string('event_id', 26)->nullable());
        }

        if (!$schema->hasIndex($activity, $activity.'_event_id_index')) {
            $schema->table($activity, fn (Blueprint $table) => $table->index('event_id'));
        }

        $name = app(DatabaseConfig::class)->table('outbox');
        foreach (['drive_id', 'aggregate_type', 'aggregate_id', 'sequence'] as $column) {
            if (!$schema->hasColumn($name, $column)) {
                $schema->table($name, static function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'drive_id' => app(IdStrategyResolver::class)->resolve()->reference($table, $column, nullable: true),
                        'aggregate_type' => $table->string($column, 32)->nullable(),
                        'aggregate_id' => $table->string($column, 64)->nullable(),
                        'sequence' => $table->bigInteger($column)->nullable(),
                    };
                });
            }
        }
    }

    public function down(): void
    {
        // Fresh-install rollback must leave canonical tables for the base migrations
        // to drop; only an earlier 0.2 batch needs historical names restored.
        if ($this->freshInstall()) {
            return;
        }

        $this->convertEvents(array_flip(self::EVENTS));
        $this->dropColumns('activity', ['event_id']);
        $this->dropColumns('outbox', ['drive_id', 'aggregate_type', 'aggregate_id', 'sequence']);
    }

    private function freshInstall(): bool
    {
        $db = app(VaulterDatabase::class)->connection();
        if (!$db->getSchemaBuilder()->hasTable('migrations')) {
            return true;
        }

        $base = $db->table('migrations')->where('migration', '2026_06_13_000007_create_v_storages_table')->value('batch');
        $upgrade = $db->table('migrations')->where('migration', '2026_10_01_000009_events')->value('batch')
            ?? $db->table('migrations')->where('migration', '2026_10_01_000001_vaulter_preflight')->value('batch');

        return $base !== null && $base === $upgrade;
    }

    /** @param array<string, string> $map */
    private function convertEvents(array $map): void
    {
        $db = app(VaulterDatabase::class)->connection();
        $schema = $db->getSchemaBuilder();
        foreach (['activity' => 'verb', 'outbox' => 'event', 'webhook_deliveries' => 'event'] as $entity => $column) {
            $table = app(DatabaseConfig::class)->table($entity);
            if (!$schema->hasTable($table)) {
                continue;
            }

            foreach ($map as $from => $to) {
                $db->table($table)->where($column, $from)->update([$column => $to]);
            }
        }

        $name = app(DatabaseConfig::class)->table('webhook_endpoints');
        if ($schema->hasTable($name)) {
            $db->table($name)->orderBy('id')->chunkById(500, static function ($rows) use ($db, $name, $map): void {
                foreach ($rows as $row) {
                    if (!is_string($row->events)) {
                        throw new UnexpectedValueException('Webhook subscriptions must be JSON.');
                    }

                    $events = json_decode($row->events, true, flags: JSON_THROW_ON_ERROR);
                    if (!is_array($events) || !array_is_list($events)) {
                        throw new UnexpectedValueException('Webhook subscriptions must be a JSON list.');
                    }

                    $mapped = array_map(static function (mixed $event) use ($map): string {
                        if (!is_string($event)) {
                            throw new UnexpectedValueException('Webhook subscription names must be strings.');
                        }

                        return $map[$event] ?? $event;
                    }, $events);
                    if ($events !== $mapped) {
                        $db->table($name)->where('id', $row->id)->update(['events' => json_encode($mapped, JSON_THROW_ON_ERROR)]);
                    }
                }
            });
        }
    }

    /** @param list<string> $columns */
    private function dropColumns(string $entity, array $columns): void
    {
        $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
        $name = app(DatabaseConfig::class)->table($entity);
        if (!$schema->hasTable($name)) {
            return;
        }

        foreach ($columns as $column) {
            if ($schema->hasColumn($name, $column)) {
                foreach ($schema->getIndexes($name) as $index) {
                    if (in_array($column, $index['columns'], true)) {
                        $schema->table($name, fn (Blueprint $table) => $index['unique'] ? $table->dropUnique($index['name']) : $table->dropIndex($index['name']));
                    }
                }

                $schema->table($name, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
