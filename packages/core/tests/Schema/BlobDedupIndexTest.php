<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/** Every configured scope uses the same NULL-free scope_key index. */
function dedupIndexColumns(): array
{
    $table = Config::table('blobs');
    $name = "{$table}_scope_sha_uniq";

    /** @var list<object{name: string}> $columns */
    $columns = DB::select("PRAGMA index_info('{$name}')");

    return array_map(static fn (object $column): string => $column->name, $columns);
}

function rebuildBlobsTable(): void
{
    $builder = new PanelTablesBuilder;
    $panel = (new Panel)->id('default');

    $builder->dropTable(panel: $panel, entity: 'blobs');
    $builder->createTable(panel: $panel, entity: 'blobs');
}

function storageKeyIndexColumns(): array
{
    $table = Config::table('blobs');
    $name = "{$table}_storage_key_uniq";

    /** @var list<object{name: string}> $columns */
    $columns = DB::select("PRAGMA index_info('{$name}')");

    return array_map(static fn (object $column): string => $column->name, $columns);
}

it('global scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'global');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'PRAGMA index introspection + dropTable(no CASCADE) are SQLite-only; the dedup UNIQUE is exercised live on PG by the Cas suite.',
);

it('tenant scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'PRAGMA index introspection + dropTable(no CASCADE) are SQLite-only; the dedup UNIQUE is exercised live on PG by the Cas suite.',
);

it('storage scope → UNIQUE (scope_key, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'storage');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['scope_key', 'sha256']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'PRAGMA index introspection + dropTable(no CASCADE) are SQLite-only; the dedup UNIQUE is exercised live on PG by the Cas suite.',
);

// The physical object address remains independently unique across disks.
it('storage_key_uniq stays single-column (the F-P1.5-03 NULL-tenant dedup backbone)', function (): void {
    config()->set('vaulter.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(storageKeyIndexColumns())->toBe(['storage_key']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'PRAGMA index introspection + dropTable(no CASCADE) are SQLite-only; the storage_key UNIQUE is exercised live on PG by the Cas suite.',
);
