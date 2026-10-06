<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Schema\SchemaBuilder;

/** Every configured scope uses the same NULL-free scope_key index. */
function dedupIndexColumns(): array
{
    $table = app(DatabaseConfig::class)->table('blobs');
    $name = $table.'_scope_sha_uniq';

    $indexes = array_column(Schema::getIndexes($table), null, 'name');

    return $indexes[$name]['columns'];
}

function rebuildBlobsTable(): void
{
    $builder = new SchemaBuilder;
    config()->set('vaulter.database.table_prefix', 'dedup_test_');

    $builder->dropTable(entity: 'blobs');
    $builder->createTable(entity: 'blobs');
}

function storageKeyIndexColumns(): array
{
    $table = app(DatabaseConfig::class)->table('blobs');
    $name = $table.'_storage_key_uniq';

    $indexes = array_column(Schema::getIndexes($table), null, 'name');

    return $indexes[$name]['columns'];
}

it('global scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter-media.dedup.scope', 'global');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
});

it('tenant scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter-media.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
});

it('storage scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter-media.dedup.scope', 'drive');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
});

// The physical object address remains independently unique across disks.
it('storage_key_uniq stays single-column (the F-P1.5-03 NULL-tenant dedup backbone)', function (): void {
    config()->set('vaulter-media.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(storageKeyIndexColumns())->toBe(['storage_key']);
});

afterEach(function (): void {
    config()->set('vaulter.database.table_prefix', 'dedup_test_');
    (new SchemaBuilder)->dropTable('blobs');
    config()->set('vaulter.database.table_prefix', 'v_');
});
