<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Drive\Configuration\DriveOptions;

/**
 * Schema green guard, no
 * behaviour delta: the hot-path index shape the drive schema emits (and
 * the 2026_07_11_000001 upgrade migration repairs on a pre-fix database) must
 * carry the (trashed_at) scan index PurgeTrashCommand's predicate needs, and
 * must NOT carry the three indexes whose column set duplicates/prefixes another
 * index already on the same table.
 *
 * Red-before: before this item, `v_nodes` carried no index with
 * `columns === ['trashed_at']` — the first assertion below failed. Red-before
 * Before this fix, `v_nodes` carried `['drive_id','parent_id']`
 * as its OWN index (inline, alongside the composite), `v_blobs` carried
 * `['status']` as its own index (inline, alongside `_gc_idx`), and `v_activity`
 * carried TWO indexes with `columns === ['created_at']` (the inline one and
 * `_created_idx`) — the negative/count assertions below failed.
 */
function hotPathIndexColumnSets(string $table): array
{
    /** @var list<array{name: string, columns: list<string>}> $indexes */
    $indexes = Schema::getIndexes($table);

    return array_map(static fn (array $index): array => $index['columns'], $indexes);
}

it('v_nodes carries a (trashed_at) scan index for the purge-trash predicate', function (): void {
    expect(hotPathIndexColumnSets('v_nodes'))->toContain(['trashed_at']);
});

it('v_nodes lists children through one (drive_id, parent_id) index now that manual positions are gone (.)', function (): void {
    $columnSets = hotPathIndexColumnSets('v_nodes');

    expect($columnSets)->toContain(['drive_id', 'parent_id'])
        ->and($columnSets)->not->toContain(['drive_id', 'parent_id', 'position']);
});

it('v_blobs does not carry a redundant single-column status index — a strict prefix of the GC index', function (): void {
    $columnSets = hotPathIndexColumnSets('v_blobs');

    expect($columnSets)->not->toContain(['status'])
        ->and($columnSets)->toContain(['status', 'ref_count', 'delete_after_at']);
});

it('v_activity carries the (created_at) feed index exactly once, not twice', function (): void {
    $duplicates = array_filter(
        hotPathIndexColumnSets('v_activity'),
        static fn (array $columns): bool => $columns === ['created_at'],
    );

    expect($duplicates)->toHaveCount(1);
})->skip(fn (): bool => !app(DriveOptions::class)->featureEnabled('activity'), 'v_activity only exists when the activity feature is enabled');
