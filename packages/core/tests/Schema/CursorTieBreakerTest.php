<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Repositories\Access\GrantReadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\Tests\Schema\ForceCreates;

/**
 * F-P4.2-05 (VLT-REMED P4.3, batch B16): `search()`, `trashed()` and
 * `GrantReadRepository::forNode()` sort by a single non-unique column with no
 * tie-breaker — `cursorPaginate()`'s keyset then compares ONLY that column
 * across the page boundary, so two rows sharing its value collide: whichever
 * one lands second in that comparison is silently SKIPPED (never returned by
 * either page).
 *
 * Red-before: before this item, each test below reproducibly lost one of the
 * two same-key rows across a 1-per-page cursor boundary. After adding the
 * `id` tie-breaker (the same pattern as
 * {@see Activity::scopeNewestFirst()}), both rows
 * survive the boundary.
 */
it('search() cursor pagination does not skip a same-name row across the page boundary (F-P4.2-05)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);

    $folderA = AccessTestHelpers::folder($storage, $root, 'folder-a');
    $folderB = AccessTestHelpers::folder($storage, $root, 'folder-b');

    // Same name, different parents — sibling-uniqueness in this package is
    // scoped per-parent (ADR-0005), so this is a legitimate, common shape:
    // two files named identically in two different folders.
    $file1 = ForceCreates::make(Node::class, [
        'drive_id' => $storage->id, 'parent_id' => $folderA->id,
        'kind' => NodeKind::File, 'name' => 'duplicate-name',
    ]);
    $file2 = ForceCreates::make(Node::class, [
        'drive_id' => $storage->id, 'parent_id' => $folderB->id,
        'kind' => NodeKind::File, 'name' => 'duplicate-name',
    ]);

    $repository = app(NodeReadRepository::class);

    $page1 = $repository->search($storage, 'duplicate-name')->cursorPaginate(1);
    $page2 = $repository->search($storage, 'duplicate-name')->cursorPaginate(1, cursor: $page1->nextCursor());

    $ids = collect($page1->items())->pluck('id')
        ->merge(collect($page2->items())->pluck('id'))
        ->unique();

    expect($ids)->toHaveCount(2)
        ->and($ids->all())->toEqualCanonicalizing([$file1->id, $file2->id]);
});

it('trashed() cursor pagination does not skip a same-timestamp row across the page boundary (F-P4.2-05)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);
    $table = app(DatabaseConfig::class)->table('nodes');

    $file1 = ForceCreates::make(Node::class, [
        'drive_id' => $storage->id, 'parent_id' => $root->id,
        'kind' => NodeKind::File, 'name' => 'trashed-1',
    ]);
    $file2 = ForceCreates::make(Node::class, [
        'drive_id' => $storage->id, 'parent_id' => $root->id,
        'kind' => NodeKind::File, 'name' => 'trashed-2',
    ]);

    // Force an IDENTICAL trashed_at on both rows — the real-world shape a
    // batch-trash operation produces (both rows stamped from the same request).
    $trashedAt = now();
    DB::table($table)->whereIn('id', [$file1->id, $file2->id])->update(['trashed_at' => $trashedAt]);

    $repository = app(NodeReadRepository::class);

    $page1 = $repository->trashed($storage)->cursorPaginate(1);
    $page2 = $repository->trashed($storage)->cursorPaginate(1, cursor: $page1->nextCursor());

    $ids = collect($page1->items())->pluck('id')
        ->merge(collect($page2->items())->pluck('id'))
        ->unique();

    expect($ids)->toHaveCount(2)
        ->and($ids->all())->toEqualCanonicalizing([$file1->id, $file2->id]);
});

it('GrantReadRepository::forNode() cursor pagination does not skip a same-timestamp grant across the page boundary (F-P4.2-05)', function (): void {
    $storage = AccessTestHelpers::storage();
    $root = Node::find($storage->root_node_id);
    $table = app(DatabaseConfig::class)->table('node_grants');
    $subjectType = (new TestUser)->getMorphClass();

    $grant1 = ForceCreates::make(NodeGrant::class, [
        'node_id' => $root->id, 'subject_type' => $subjectType, 'subject_id' => 1,
        'role_key' => RoleRegistry::READER, 'role_version' => 1, 'scope' => GrantScope::Subtree->value,
    ]);
    $grant2 = ForceCreates::make(NodeGrant::class, [
        'node_id' => $root->id, 'subject_type' => $subjectType, 'subject_id' => 2,
        'role_key' => RoleRegistry::READER, 'role_version' => 1, 'scope' => GrantScope::Subtree->value,
    ]);

    // Force an IDENTICAL created_at — grants written in the same
    // transaction/batch share a timestamp.
    $createdAt = now();
    DB::table($table)->whereIn('id', [$grant1->id, $grant2->id])->update(['created_at' => $createdAt]);

    $repository = app(GrantReadRepository::class);

    $page1 = $repository->forNode($root)->cursorPaginate(1);
    $page2 = $repository->forNode($root)->cursorPaginate(1, cursor: $page1->nextCursor());

    $ids = collect($page1->items())->pluck('id')
        ->merge(collect($page2->items())->pluck('id'))
        ->unique();

    expect($ids)->toHaveCount(2)
        ->and($ids->all())->toEqualCanonicalizing([$grant1->id, $grant2->id]);
});
