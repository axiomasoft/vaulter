<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Media\Schema\MediaSchema;

/** Every configured scope uses the same NULL-free scope_key index. */
function dedupIndexColumns(): array
{
    $table = app(DatabaseOptions::class)->table('blobs');
    $name = $table.'_scope_sha_uniq';

    $indexes = array_column(Schema::getIndexes($table), null, 'name');

    return $indexes[$name]['columns'];
}

function rebuildBlobsTable(): void
{
    config()->set('vaulter.database.table_prefix', 'dedup_test_');
    $builder = app(MediaSchema::class);

    $builder->drop(entity: 'blobs');
    $builder->create(entity: 'blobs');
}

function storageKeyIndexColumns(): array
{
    $table = app(DatabaseOptions::class)->table('blobs');
    $name = $table.'_storage_key_uniq';

    $indexes = array_column(Schema::getIndexes($table), null, 'name');

    return $indexes[$name]['columns'];
}

it('off scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter-media.dedup.scope', 'off');
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
it('storage_key_uniq stays single-column (the  NULL-tenant dedup backbone)', function (): void {
    config()->set('vaulter-media.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(storageKeyIndexColumns())->toBe(['storage_key']);
});

afterEach(function (): void {
    config()->set('vaulter.database.table_prefix', 'dedup_test_');
    (app(MediaSchema::class))->drop('blobs');
    config()->set('vaulter.database.table_prefix', 'v_');
});
