<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Panels\Panel;
use Vaulter\Schema\PanelTablesBuilder;
use Vaulter\Support\Config;

/**
 * The dedup-UNIQUE index column set is finalised per config('vaulter.dedup.scope')
 * (H.3 — resolves the M1a note that tenant_id was emitted unconditionally):
 * global → (sha256), tenant → (tenant_id, sha256), storage → (storage_id, sha256).
 * The base suite runs sqlite, so we introspect the index columns from PRAGMA.
 */
function dedupIndexColumns(): array
{
    $table = Config::table('blobs');
    $name = "{$table}_dedup_uniq";

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

it('global scope → UNIQUE (sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'global');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['sha256']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'PRAGMA index introspection + dropTable(no CASCADE) are SQLite-only; the dedup UNIQUE is exercised live on PG by the Cas suite.',
);

it('tenant scope → UNIQUE (tenant_id, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['tenant_id', 'sha256']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'PRAGMA index introspection + dropTable(no CASCADE) are SQLite-only; the dedup UNIQUE is exercised live on PG by the Cas suite.',
);

it('storage scope → UNIQUE (storage_id, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'storage');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['storage_id', 'sha256']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'PRAGMA index introspection + dropTable(no CASCADE) are SQLite-only; the dedup UNIQUE is exercised live on PG by the Cas suite.',
);

/*
 * F-P1.5-03 (Minor) / D18 / ADR-0012 Amendment — guard test (green, no behaviour
 * delta, D19): storage_key_uniq is the TRUE race backbone for scope=tenant with a
 * NULL tenant_id (the dedup-UNIQUE above never fires there — NULL ≠ NULL in SQL).
 * That backbone role depends ENTIRELY on storage_key_uniq staying a single-column
 * index — composing it with `disk` (or anything else) would let two different
 * disks each keep a "unique" row for the same storage_key, breaking BOTH the
 * NULL-tenant dedup convergence AND the disk-agnostic orphan probe
 * (BlobStoreRepository::findByStorageKey()). This pins the index shape so a future
 * schema change can't silently widen it.
 */
it('storage_key_uniq stays single-column (the F-P1.5-03 NULL-tenant dedup backbone)', function (): void {
    config()->set('vaulter.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(storageKeyIndexColumns())->toBe(['storage_key']);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'PRAGMA index introspection + dropTable(no CASCADE) are SQLite-only; the storage_key UNIQUE is exercised live on PG by the Cas suite.',
);
