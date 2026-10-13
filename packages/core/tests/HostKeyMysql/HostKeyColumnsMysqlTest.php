<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Schema\HostKeyColumns;

beforeEach(function (): void {
    if (!in_array(app(VaulterDatabase::class)->driver(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MySQL host-key DDL requires MySQL or MariaDB.');
    }
    config()->set('vaulter.database.table_prefix', 'hk_test_');
});

it('creates each configured MySQL host type with its nullability and index', function (string $type): void {
    config()->set('vaulter.ids.host_keys', $type);
    $db = app(VaulterDatabase::class)->connection();
    $schema = $db->getSchemaBuilder();
    $table = app(DatabaseConfig::class)->table('node_grants');
    try {
        $schema->create($table, function (Blueprint $blueprint): void {
            HostKeyColumns::add($blueprint, 'subject_id', nullable: false, index: true);
            HostKeyColumns::add($blueprint, 'granted_by', nullable: true, index: false);
        });
        $metadata = array_column($schema->getColumns($table), null, 'name');
        expect($metadata['subject_id']['type'])->toBe(HostKeyColumns::sqlType('mysql'))
            ->and($metadata['subject_id']['nullable'])->toBeFalse()
            ->and($metadata['granted_by']['type'])->toBe(HostKeyColumns::sqlType('mysql'))
            ->and($metadata['granted_by']['nullable'])->toBeTrue()
            ->and(array_column($schema->getIndexes($table), 'columns'))->toBe([['subject_id']]);
    } finally {
        $schema->dropIfExists($table);
    }
})->with(['string', 'bigint', 'uuid', 'ulid']);
