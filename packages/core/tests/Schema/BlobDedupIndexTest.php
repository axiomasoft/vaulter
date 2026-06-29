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

it('global scope → UNIQUE (sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'global');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['sha256']);
});

it('tenant scope → UNIQUE (tenant_id, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'tenant');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['tenant_id', 'sha256']);
});

it('storage scope → UNIQUE (storage_id, sha256)', function (): void {
    config()->set('vaulter.dedup.scope', 'storage');
    rebuildBlobsTable();

    expect(dedupIndexColumns())->toBe(['storage_id', 'sha256']);
});
