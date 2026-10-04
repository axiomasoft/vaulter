<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Config;

return new class extends Migration
{
    /** @var array<string, array<string, bool>> */
    private const array COLUMNS = [
        'drives' => ['tenant_id' => true, 'owner_id' => false, 'created_by' => true],
        'nodes' => ['tenant_id' => true, 'owner_id' => true, 'trashed_by' => true, 'created_by' => true, 'updated_by' => true],
        'blobs' => ['tenant_id' => true],
        'files' => ['tenant_id' => true, 'created_by' => true],
        'file_revisions' => ['tenant_id' => true, 'created_by' => true],
        'node_grants' => ['tenant_id' => true, 'subject_id' => false, 'granted_by' => true],
        'node_shares' => ['tenant_id' => true, 'granted_by' => true],
        'node_comments' => ['tenant_id' => true, 'author_id' => false, 'resolved_by' => true],
        'node_document_versions' => ['tenant_id' => true, 'created_by' => true],
        'upload_sessions' => ['tenant_id' => true, 'created_by' => true],
        'activity' => ['tenant_id' => true, 'actor_id' => true],
    ];

    public function up(): void
    {
        $this->convert(restore: false);
    }

    public function down(): void
    {
        $this->convert(restore: true);
    }

    private function convert(bool $restore): void
    {
        $connection = app(VaulterDatabase::class)->connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $schema = $connection->getSchemaBuilder();
        $grammar = $connection->getQueryGrammar();

        foreach (self::COLUMNS as $entity => $columns) {
            $table = Config::table($entity);
            // #3 precedes the revision-table rename in #4, including on rollback.
            if ($entity === 'file_revisions' && !$schema->hasTable($table)) {
                $table = $schema->hasTable(Config::table('file_versions')) ? Config::table('file_versions') : Config::legacyTable('file_versions');
            }

            if (!$schema->hasTable($table)) {
                continue;
            }

            $actual = array_column($schema->getColumns($table), null, 'name');

            foreach ($columns as $column => $nullable) {
                if (!isset($actual[$column])) {
                    continue;
                }

                $type = $restore
                    ? $this->originalType(new Blueprint($connection, $table), $entity, $column, $driver)
                    : HostKeyColumns::sqlType($driver);

                // type_name alone cannot distinguish varchar(26) from varchar(64)
                // or a signed bigint from an unsigned MySQL bigint.
                $actualType = str_replace(['character varying', 'character'], ['varchar', 'char'], strtolower($actual[$column]['type']));
                if ($actualType === $type) {
                    continue;
                }

                $wrappedTable = $grammar->wrapTable($table);
                $wrappedColumn = $grammar->wrap($column);

                if ($driver === 'pgsql') {
                    $cast = match ($type) {
                        'bigint' => 'bigint',
                        'uuid' => 'uuid',
                        default => 'text',
                    };
                    $connection->statement("ALTER TABLE {$wrappedTable} ALTER COLUMN {$wrappedColumn} TYPE {$type} USING {$wrappedColumn}::{$cast}");
                } else {
                    $null = $nullable ? 'NULL' : 'NOT NULL';
                    $connection->statement("ALTER TABLE {$wrappedTable} MODIFY {$wrappedColumn} {$type} {$null}");
                }
            }
        }
    }

    private function originalType(Blueprint $blueprint, string $entity, string $column, string $driver): string
    {
        $strategy = app(IdStrategyResolver::class)->resolve();
        $reference = $column === 'tenant_id'
            || ($entity === 'drives' && $column === 'owner_id')
            || $column === 'subject_id'
            || $column === 'author_id';

        if ($reference) {
            $strategy->reference($blueprint, $column);
        } else {
            $strategy->actor($blueprint, $column);
        }

        $definition = $blueprint->getColumns()[0];
        $type = $definition->get('type');
        $length = $definition->get('length');

        if (!is_string($type) || (in_array($type, ['char', 'string'], true) && !is_int($length))) {
            throw new LogicException('[Vaulter] Invalid rollback host-key definition.');
        }

        return match ($type) {
            'bigInteger' => $driver !== 'pgsql' && $definition->get('unsigned') ? 'bigint unsigned' : 'bigint',
            'char' => 'char('.$length.')',
            'string' => 'varchar('.$length.')',
            'uuid' => $driver === 'pgsql' ? 'uuid' : 'char(36)',
            default => throw new LogicException('[Vaulter] Unsupported rollback host-key type ['.$type.'].'),
        };
    }
};
