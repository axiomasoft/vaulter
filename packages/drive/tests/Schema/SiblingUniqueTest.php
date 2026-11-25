<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;

/**
 * The sibling-unique guard must reject a duplicate LIVE sibling name but
 * allow a duplicate name once the rival is trashed or purged.
 */
function makeStorage(): Drive
{
    // forceFill: owner_type/owner_id are excluded from
    // Storage's $fillable — this trusted fixture builder bypasses the guard
    // the same way DriveStoreRepository does.
    $storage = new Drive;
    $storage->forceFill([
        'owner_type' => 'team',
        'owner_id' => 1,
        'name' => 'Disk',
    ]);
    $storage->save();

    return $storage;
}

function makeNode(Drive $storage, string $name, array $overrides = []): Node
{
    $node = new Node;
    $node->forceFill([
        'drive_id' => $storage->getKey(),
        'parent_id' => $overrides['parent_id'] ?? null,
        'kind' => NodeKind::Folder,
        'name' => $name,
        ...$overrides,
    ]);
    $node->save();

    return $node;
}

it('rejects a duplicate name of a live sibling under the same parent', function (): void {
    $storage = makeStorage();
    $parent = makeNode($storage, 'root');

    makeNode($storage, 'report', ['parent_id' => $parent->getKey()]);

    // SAVEPOINT the duplicate: on PostgreSQL the UNIQUE violation would otherwise abort the
    // enclosing RefreshDatabase transaction (see BlobDedupScopeTest).
    expect(fn () => DB::transaction(fn (): Node => makeNode($storage, 'report', ['parent_id' => $parent->getKey()])))
        ->toThrow(QueryException::class);
});

it('allows a duplicate name when the competitor is in the trash (trashed_at)', function (): void {
    $storage = makeStorage();
    $parent = makeNode($storage, 'root2');

    $first = makeNode($storage, 'doc', ['parent_id' => $parent->getKey()]);

    // Move the rival to the trash — its name frees up for a live sibling.
    DB::table('v_nodes')->where('id', $first->getKey())->update(['trashed_at' => now()]);

    $second = makeNode($storage, 'doc', ['parent_id' => $parent->getKey()]);

    expect($second->exists)->toBeTrue();
});

it('allows a duplicate name after the competitor is purged (nodes have no deleted_at)', function (): void {
    $storage = makeStorage();
    $parent = makeNode($storage, 'root3');

    $first = makeNode($storage, 'note', ['parent_id' => $parent->getKey()]);
    $first->delete();

    $second = makeNode($storage, 'note', ['parent_id' => $parent->getKey()]);

    expect($second->exists)->toBeTrue();
});
