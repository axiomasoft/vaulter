<?php

declare(strict_types=1);

use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Schema\VaulterMigration;

return new class extends VaulterMigration
{
    public function up(): void
    {
        $connection = $this->schema()->getConnection();
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $grammar = $connection->getSchemaGrammar();
        foreach ([OutboxStore::EVENTS, OutboxStore::DELIVERIES] as $logical) {
            $table = $grammar->wrapTable($this->table($logical));
            $connection->statement(
                "ALTER TABLE {$table} SET (autovacuum_vacuum_scale_factor = 0, autovacuum_vacuum_threshold = 2000)",
            );
        }
    }

    public function down(): void
    {
        $connection = $this->schema()->getConnection();
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $grammar = $connection->getSchemaGrammar();
        foreach ([OutboxStore::EVENTS, OutboxStore::DELIVERIES] as $logical) {
            $table = $grammar->wrapTable($this->table($logical));
            $connection->statement(
                "ALTER TABLE {$table} RESET (autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold)",
            );
        }
    }
};
