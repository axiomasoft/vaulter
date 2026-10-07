<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

/**
 * The sibling-unique guard (§2.2) must reject a duplicate LIVE sibling name but
 * allow a duplicate name once the rival is trashed or purged.
 */
function makeStorage(): Drive
{
    // forceFill (P1.4/F-P1.8-02): owner_type/owner_id are excluded from
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

it('отвергает дубль имени живого сиблинга в том же родителе', function (): void {
    $storage = makeStorage();
    $parent = makeNode($storage, 'root');

    makeNode($storage, 'report', ['parent_id' => $parent->getKey()]);

    // SAVEPOINT the duplicate: on PostgreSQL the UNIQUE violation would otherwise abort the
    // enclosing RefreshDatabase transaction (see BlobDedupScopeTest).
    expect(fn () => DB::transaction(fn (): Node => makeNode($storage, 'report', ['parent_id' => $parent->getKey()])))
        ->toThrow(QueryException::class);
});

it('разрешает дубль имени, если конкурент в корзине (trashed_at)', function (): void {
    $storage = makeStorage();
    $parent = makeNode($storage, 'root2');

    $first = makeNode($storage, 'doc', ['parent_id' => $parent->getKey()]);

    // Move the rival to the trash — its name frees up for a live sibling.
    DB::table('v_nodes')->where('id', $first->getKey())->update(['trashed_at' => now()]);

    $second = makeNode($storage, 'doc', ['parent_id' => $parent->getKey()]);

    expect($second->exists)->toBeTrue();
});

it('разрешает дубль имени после purge конкурента (узлы без deleted_at)', function (): void {
    $storage = makeStorage();
    $parent = makeNode($storage, 'root3');

    $first = makeNode($storage, 'note', ['parent_id' => $parent->getKey()]);
    $first->delete();

    $second = makeNode($storage, 'note', ['parent_id' => $parent->getKey()]);

    expect($second->exists)->toBeTrue();
});
