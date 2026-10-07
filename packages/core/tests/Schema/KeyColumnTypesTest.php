<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Schema\KeyColumnInspector;
use Vaulter\Schema\SchemaBuilder;

it('uses the configured types for every key column on a fresh schema', function (string $strategy, string $hostKeys): void {
    if (app(VaulterDatabase::class)->driver() === 'sqlite') {
        $this->markTestSkipped('SQLite uses dynamic typing.');
    }

    config()->set('vaulter.ids.strategy', $strategy);
    config()->set('vaulter.ids.host_keys', $hostKeys);
    $prefix = sprintf('kt_%s_%s_', match ($strategy) {
        'bigint' => 'b',
        'ulid' => 'u',
        'uuid7' => 'v',
    }, $hostKeys === 'bigint' ? 'b' : 's');
    config()->set('vaulter.database.table_prefix', $prefix);
    foreach ([DatabaseConfig::class, IdsConfig::class, VaulterConfig::class, VaulterDatabase::class, IdStrategyResolver::class] as $binding) {
        $this->app->forgetInstance($binding);
    }

    $builder = new SchemaBuilder;

    try {
        $builder->createAll();

        $inspector = new KeyColumnInspector(
            app(VaulterDatabase::class),
            app(DatabaseConfig::class),
            app(IdsConfig::class),
        );
        $expected = $inspector->expected();
        $columns = array_map(static fn ($item): string => $item->column, $expected);

        expect($expected)->toHaveCount(43)
            ->and($columns)->toContain(
                'drive_id',
                'file_revision_id',
                'target_node_id',
                'result_node_id',
                'result_revision_id',
                'aggregate_id',
                'event_id',
                'profile',
            )
            ->and($inspector->mismatches())->toBe([]);

        if (app(VaulterDatabase::class)->driver() === 'mysql' && $strategy === 'bigint' && $hostKeys === 'string') {
            $database = app(DatabaseConfig::class);
            $connection = app(VaulterDatabase::class)->connection();
            $schema = $connection->getSchemaBuilder();
            $drives = $database->table('drives');
            $nodes = $database->table('nodes');

            expect($schema->hasIndex($nodes, $nodes.'_sibling_uniq'))->toBeTrue()
                ->and($schema->hasIndex($nodes, $nodes.'_root_uniq'))->toBeTrue()
                ->and($schema->hasColumn($nodes, 'sibling_active'))->toBeFalse()
                ->and($schema->hasColumn($nodes, 'root_drive'))->toBeFalse()
                ->and(array_column($schema->getColumns($database->table('blobs')), null, 'name')['storage_key']['type_name'])->toBe('varchar');

            $connection->table($drives)->insert(['id' => 1, 'owner_id' => 'owner', 'owner_type' => 'user', 'name' => 'Drive']);
            $connection->table($nodes)->insert(['id' => 1, 'drive_id' => 1, 'parent_id' => null, 'kind' => 'folder', 'name' => 'Root', 'name_key' => 'root']);
            expect(fn () => $connection->table($nodes)->insert(['id' => 2, 'drive_id' => 1, 'parent_id' => null, 'kind' => 'folder', 'name' => 'Other root', 'name_key' => 'other root']))
                ->toThrow(QueryException::class, $nodes.'_root_uniq');

            $connection->table($nodes)->insert(['id' => 2, 'drive_id' => 1, 'parent_id' => 1, 'kind' => 'folder', 'name' => 'Folder', 'name_key' => 'folder']);
            expect(fn () => $connection->table($nodes)->insert(['id' => 3, 'drive_id' => 1, 'parent_id' => 1, 'kind' => 'folder', 'name' => 'folder', 'name_key' => 'folder']))
                ->toThrow(QueryException::class, $nodes.'_sibling_uniq');

            $connection->table($drives)->where('id', 1)->delete();
            expect($connection->table($nodes)->count())->toBe(0);
        }
    } finally {
        $builder->dropAll();
    }
})->with([
    ['bigint', 'string'],
    ['bigint', 'bigint'],
    ['ulid', 'string'],
    ['ulid', 'bigint'],
    ['uuid7', 'string'],
    ['uuid7', 'bigint'],
]);
