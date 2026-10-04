<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Config;

beforeEach(function (): void {
    if (!in_array(app(VaulterDatabase::class)->driver(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MySQL host-key DDL requires MySQL or MariaDB.');
    }
    config()->set('vaulter.table_prefix', 'hk_test_');
});

it('preserves values, nullability and indexes when upgrading and restoring MySQL host keys', function (string $strategy): void {
    config()->set('vaulter.ids.default', $strategy);
    config()->set('vaulter.ids.host_keys', 'string');
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    $ids = app(IdStrategyResolver::class)->resolve();
    $value = $strategy === 'bigint' ? '42' : '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $map = [
        'storages' => ['tenant_id' => true, 'owner_id' => false, 'created_by' => true],
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
    try {
        foreach ($map as $entity => $columns) {
            $table = Config::table($entity);
            $schema->create($table, function (Blueprint $blueprint) use ($columns, $ids): void {
                foreach ($columns as $column => $nullable) {
                    if ($column === 'tenant_id' || !$nullable) {
                        $ids->reference($blueprint, $column, $nullable);
                    } else {
                        $ids->actor($blueprint, $column, $nullable);
                    }
                }
            });
            $db->table($table)->insert(array_fill_keys(array_keys($columns), $value));
        }
        $indexes = [];
        foreach ($map as $entity => $columns) {
            $indexes[$entity] = $schema->getIndexes(Config::table($entity));
        }
        $migration = require __DIR__.'/../../database/migrations/2026_10_01_000003_host_key_columns.php';
        $migration->up();
        $queries = [];
        $db->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $migration->up();
        expect(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE')))->toBe([]);
        foreach ($map as $entity => $columns) {
            $table = Config::table($entity);
            $metadata = array_column($schema->getColumns($table), null, 'name');
            $row = (array) $db->table($table)->first();
            expect($schema->getIndexes($table))->toBe($indexes[$entity]);
            foreach ($columns as $column => $nullable) {
                expect($metadata[$column]['type'])->toBe('varchar(64)')
                    ->and($metadata[$column]['nullable'])->toBe($nullable)
                    ->and($row[$column])->toBe($value);
            }
        }
        $migration->down();
        foreach ($map as $entity => $columns) {
            $metadata = array_column($schema->getColumns(Config::table($entity)), null, 'name');
            foreach ($columns as $column => $nullable) {
                expect($metadata[$column]['type'])->toBe($strategy === 'bigint' ? 'bigint unsigned' : 'char(26)');
            }
        }
    } finally {
        foreach (array_keys($map) as $entity) {
            $schema->dropIfExists(Config::table($entity));
        }
    }
})->with(['bigint', 'ulid']);

it('creates each configured MySQL host type and leaves it unchanged on upgrade', function (string $type): void {
    config()->set('vaulter.ids.host_keys', $type);
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    $table = Config::table('node_grants');
    try {
        $schema->create($table, function (Blueprint $blueprint): void {
            HostKeyColumns::add($blueprint, 'subject_id', nullable: false, index: true);
            HostKeyColumns::add($blueprint, 'granted_by', nullable: true, index: false);
        });
        $migration = require __DIR__.'/../../database/migrations/2026_10_01_000003_host_key_columns.php';
        $queries = [];
        $db->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $migration->up();
        $metadata = array_column($schema->getColumns($table), null, 'name');
        expect($metadata['subject_id']['type'])->toBe(HostKeyColumns::sqlType('mysql'))
            ->and(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'ALTER TABLE')))->toBe([]);
    } finally {
        $schema->dropIfExists($table);
    }
})->with(['string', 'bigint', 'uuid', 'ulid']);
