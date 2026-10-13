<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Schema\HostKeyColumns;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Schema\VaulterSchema;

it('creates host keys independently of entity IDs on a fresh panel', function (string $type): void {
    config()->set('vaulter.ids.host_keys', $type);
    config()->set('vaulter.database.table_prefix', 'hostkeys_');
    config()->set('vaulter-documents.comments.enabled', true);
    config()->set('vaulter.features.share_links', true);
    $builder = new SchemaBuilder;
    $builder->createAll();

    try {
        $columns = array_column(Schema::getColumns(app(DatabaseConfig::class)->table('node_grants')), null, 'name');
        $driver = DB::connection()->getDriverName();
        $expected = match ($type) {
            'string' => 'varchar',
            'bigint' => $driver === 'sqlite' ? 'integer' : ($driver === 'pgsql' ? 'int8' : 'bigint'),
            'uuid' => $driver === 'pgsql' ? 'uuid' : ($driver === 'sqlite' ? 'varchar' : 'char'),
            'ulid' => $driver === 'pgsql' ? 'bpchar' : ($driver === 'sqlite' ? 'varchar' : 'char'),
        };
        expect($columns['subject_id']['type_name'])->toBe($expected)
            ->and($columns['subject_id']['nullable'])->toBeFalse()
            ->and($columns['node_id']['type_name'])->toBe($driver === 'sqlite' ? 'integer' : ($driver === 'pgsql' ? 'int8' : 'bigint'));
    } finally {
        $builder->dropAll();
    }
})->with(['string', 'bigint', 'uuid', 'ulid']);

it('rejects unknown host key configuration', function ($value): void {
    config()->set('vaulter.ids.host_keys', $value);
    expect(fn () => app(IdsConfig::class)->hostKeys())->toThrow(InvalidArgumentException::class);
})->with(['unknown', 42, null, ['nested']]);

it('preserves nullable and index conventions for migration helpers', function (): void {
    config()->set('vaulter.tenancy.enabled', true);
    $table = new Blueprint(DB::connection(), 'host_key_probe');
    VaulterSchema::hostKey($table, 'owner_id');
    VaulterSchema::actor($table, 'created_by');
    VaulterSchema::tenant($table);
    $columns = array_column($table->getColumns(), null, 'name');
    expect($columns['owner_id']->type)->toBe('string')
        ->and($columns['owner_id']->length)->toBe(64)
        ->and($columns['owner_id']->nullable)->toBeFalse()
        ->and($columns['created_by']->nullable)->toBeTrue()
        ->and($columns['tenant_id']->nullable)->toBeTrue();
    // Fluent indexes are compiled when toSql() is called.
    $table->toSql();
    $indexes = array_values(array_filter($table->getCommands(), static fn ($command): bool => $command->name === 'index'));
    expect(array_map(static fn ($index): array => $index->columns, $indexes))->toBe([['tenant_id']]);
});

it('maps every supported SQL driver and host type', function (string $type, string $pgsql, string $mysql): void {
    config()->set('vaulter.ids.host_keys', $type);
    expect(HostKeyColumns::sqlType('pgsql'))->toBe($pgsql)
        ->and(HostKeyColumns::sqlType('mysql'))->toBe($mysql)
        ->and(HostKeyColumns::sqlType('mariadb'))->toBe($mysql);
})->with([
    ['string', 'varchar(64)', 'varchar(64)'],
    ['bigint', 'bigint', 'bigint unsigned'],
    ['uuid', 'uuid', 'char(36)'],
    ['ulid', 'char(26)', 'char(26)'],
]);
