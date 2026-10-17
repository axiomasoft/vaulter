<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Schema\DriveSchema;
use Vaulter\Drive\Tests\TestCase;

uses(TestCase::class);

it('creates the node sequence as a signed 64-bit column with enforced foreign keys', function (string $strategy): void {
    $oldPrefix = config('vaulter.database.table_prefix');
    $oldStrategy = config('vaulter.ids.strategy');
    config()->set('vaulter.database.table_prefix', 'sequence_probe_');
    config()->set('vaulter.ids.strategy', $strategy);
    $builder = app(DriveSchema::class);
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    if ($db->getDriverName() === 'sqlite') {
        $db->statement('PRAGMA foreign_keys = ON');
        expect((int) $db->selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
    }
    $key = static fn (int $number): int|string => match ($strategy) {
        'bigint' => $number,
        'ulid' => (string) Str::ulid(),
        'uuid7' => (string) Str::uuid7(),
    };
    [$drive, $parent, $child, $absent] = array_map($key, [1, 2, 3, 4]);

    try {
        $builder->create('drives');
        $builder->create('nodes');
        expect($schema->hasColumn('sequence_probe_nodes', 'sequence'))->toBeTrue();
        $db->table('sequence_probe_drives')->insert([
            'id' => $drive, 'owner_type' => 'team', 'owner_id' => '1', 'name' => 'drive',
        ]);
        $db->table('sequence_probe_nodes')->insert([
            ['id' => $parent, 'drive_id' => $drive, 'parent_id' => null, 'kind' => 'folder', 'name' => 'parent', 'name_key' => 'parent'],
            ['id' => $child, 'drive_id' => $drive, 'parent_id' => $parent, 'kind' => 'file', 'name' => 'child', 'name_key' => 'child'],
        ]);
        foreach ($db->table('sequence_probe_nodes')->get() as $row) {
            expect((int) $row->sequence)->toBe(0);
        }
        $column = collect($schema->getColumns('sequence_probe_nodes'))->firstWhere('name', 'sequence');
        expect(strtolower($column['type_name']))->toBeIn(['bigint', 'int8']);
        if ($db->getDriverName() === 'mysql') {
            expect(strtolower($column['type']))->not->toContain('unsigned');
        }

        $db->table('sequence_probe_nodes')->where('id', $child)->update(['sequence' => PHP_INT_MAX]);
        expect((string) $db->table('sequence_probe_nodes')->where('id', $child)->value('sequence'))->toBe((string) PHP_INT_MAX);
        foreach ([-1, '9223372036854775808'] as $invalid) {
            expect(fn () => $db->transaction(fn () => $db->table('sequence_probe_nodes')->where('id', $child)->update(['sequence' => $invalid])))
                ->toThrow(QueryException::class);
            expect((string) $db->table('sequence_probe_nodes')->where('id', $child)->value('sequence'))->toBe((string) PHP_INT_MAX);
        }
        expect(fn () => $db->transaction(fn () => $db->table('sequence_probe_nodes')->where('id', $child)->update(['parent_id' => $absent])))
            ->toThrow(QueryException::class);
        expect(fn () => $db->transaction(fn () => $db->table('sequence_probe_nodes')->where('id', $child)->update(['drive_id' => $absent])))
            ->toThrow(QueryException::class);
    } finally {
        $builder->drop('nodes');
        $builder->drop('drives');
        config()->set('vaulter.database.table_prefix', $oldPrefix);
        config()->set('vaulter.ids.strategy', $oldStrategy);
    }
})->with(['bigint', 'ulid', 'uuid7']);

it('D33 refuses to add the node sequence on a mysql-driver server that cannot enforce CHECK', function (string $version, string $mode): void {
    $database = DB::getFacadeRoot();
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('selectOne')->andReturn((object) ['v' => $version, 'm' => $mode]);
    $connection->shouldNotReceive('statement');
    DB::shouldReceive('connection')->andReturn($connection);

    try {
        expect(fn () => app(DriveSchema::class)->addNodeSequence())->toThrow(RuntimeException::class, 'enforced MySQL CHECKs');
    } finally {
        DB::swap($database);
    }
})->with([
    'MariaDB server behind the mysql driver' => ['10.11.6-MariaDB-0ubuntu0.22.04.1', 'STRICT_TRANS_TABLES'],
    'MySQL older than 8.0.16' => ['8.0.15', 'STRICT_TRANS_TABLES'],
    'MySQL without strict mode' => ['8.0.46', 'NO_ENGINE_SUBSTITUTION'],
]);
