<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Enums\NodeKind;
use Vaulter\Models\Node;
use Vaulter\Models\Storage;

/**
 * The sibling-unique guard (§2.2) must reject a duplicate LIVE sibling name but
 * allow a duplicate name once the rival is trashed or soft-deleted.
 */
function makeStorage(): Storage
{
    return Storage::query()->create([
        'owner_type' => 'team',
        'owner_id' => 1,
        'name' => 'Disk',
    ]);
}

function makeNode(Storage $storage, string $name, array $overrides = []): Node
{
    return Node::query()->create([
        'storage_id' => $storage->getKey(),
        'parent_id' => $overrides['parent_id'] ?? null,
        'kind' => NodeKind::Folder,
        'name' => $name,
        ...$overrides,
    ]);
}

it('отвергает дубль имени живого сиблинга в том же родителе', function (): void {
    $storage = makeStorage();
    $parent = makeNode($storage, 'root');

    makeNode($storage, 'report', ['parent_id' => $parent->getKey()]);

    expect(fn () => makeNode($storage, 'report', ['parent_id' => $parent->getKey()]))
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

it('разрешает дубль имени, если конкурент soft-deleted (deleted_at)', function (): void {
    $storage = makeStorage();
    $parent = makeNode($storage, 'root3');

    $first = makeNode($storage, 'note', ['parent_id' => $parent->getKey()]);
    $first->delete();

    $second = makeNode($storage, 'note', ['parent_id' => $parent->getKey()]);

    expect($second->exists)->toBeTrue();
});
