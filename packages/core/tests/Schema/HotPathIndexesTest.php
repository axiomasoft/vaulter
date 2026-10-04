<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Support\Config;

/**
 * F-P4.1-01 / F-P4.1-02 (VLT-REMED P4.3, batch B16) — schema green guard, no
 * behaviour delta (D19): the hot-path index shape SchemaBuilder emits (and
 * the 2026_07_11_000001 upgrade migration repairs on a pre-fix database) must
 * carry the (trashed_at) scan index PurgeTrashCommand's predicate needs, and
 * must NOT carry the three indexes whose column set duplicates/prefixes another
 * index already on the same table (findings/P4.1-query-indexes.md §0/C7).
 *
 * Red-before (F-P4.1-01): before this item, `v_nodes` carried no index with
 * `columns === ['trashed_at']` — the first assertion below failed. Red-before
 * (F-P4.1-02): before this item, `v_nodes` carried `['storage_id','parent_id']`
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

it('v_nodes carries a (trashed_at) scan index for the purge-trash predicate (F-P4.1-01)', function (): void {
    expect(hotPathIndexColumnSets('v_nodes'))->toContain(['trashed_at']);
});

it('v_nodes does not carry a redundant (storage_id, parent_id) index — a strict prefix of (storage_id, parent_id, position) (F-P4.1-02)', function (): void {
    $columnSets = hotPathIndexColumnSets('v_nodes');

    expect($columnSets)->not->toContain(['storage_id', 'parent_id'])
        ->and($columnSets)->toContain(['storage_id', 'parent_id', 'position']);
});

it('v_blobs does not carry a redundant single-column status index — a strict prefix of the GC index (F-P4.1-02)', function (): void {
    $columnSets = hotPathIndexColumnSets('v_blobs');

    expect($columnSets)->not->toContain(['status'])
        ->and($columnSets)->toContain(['status', 'ref_count', 'delete_after_at']);
})->skip(fn (): bool => !Config::featureEnabled('blobs'), 'v_blobs only exists when the blobs feature is enabled');

it('v_activity carries the (created_at) feed index exactly once, not twice (F-P4.1-02)', function (): void {
    $duplicates = array_filter(
        hotPathIndexColumnSets('v_activity'),
        static fn (array $columns): bool => $columns === ['created_at'],
    );

    expect($duplicates)->toHaveCount(1);
})->skip(fn (): bool => !Config::featureEnabled('activity'), 'v_activity only exists when the activity feature is enabled');
