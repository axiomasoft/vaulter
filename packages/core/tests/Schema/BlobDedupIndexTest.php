<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Schema\SchemaBuilder;
use Vaulter\Support\Config;

/** Every configured scope uses the same NULL-free scope_key index. */
function dedupIndexColumns(): array
{
    $table = Config::table('blobs');
    $name = $table.'_scope_sha_uniq';

    $indexes = array_column(Schema::getIndexes($table), null, 'name');

    return $indexes[$name]['columns'];
}

function rebuildBlobsTable(): void
{
    $builder = new SchemaBuilder;
    config()->set('vaulter.table_prefix', 'dedup_test_');

    $builder->dropTable(entity: 'blobs');
    $builder->createTable(entity: 'blobs');
}

function storageKeyIndexColumns(): array
{
    $table = Config::table('blobs');
    $name = $table.'_storage_key_uniq';

    $indexes = array_column(Schema::getIndexes($table), null, 'name');

    return $indexes[$name]['columns'];
}

it('global scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'global');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
});

it('tenant scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
});

it('storage scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'storage');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
});

// The physical object address remains independently unique across disks.
it('storage_key_uniq stays single-column (the F-P1.5-03 NULL-tenant dedup backbone)', function (): void {
    config()->set('vaulter.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(storageKeyIndexColumns())->toBe(['storage_key']);
});

afterEach(function (): void {
    config()->set('vaulter.table_prefix', 'dedup_test_');
    (new SchemaBuilder)->dropTable('blobs');
    config()->set('vaulter.table_prefix', 'v_');
});
