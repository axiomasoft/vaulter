<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Exceptions\NodeNameConflictException;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('trash помечает узел и всё поддерево, used_bytes не меняется', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->createFolder('Project');
    $sub = $drive->createFolder('Sub', $folder);
    $drive->upload(DriveTestHelpers::streamFor('bytes here'), into: $sub, originalName: 'f.bin');

    $usedBefore = $drive->storage()->refresh()->used_bytes;
    expect($usedBefore)->toBe(10);

    $drive->trash($folder);

    // The whole subtree is trashed atomically.
    expect($folder->refresh()->trashed_at)->not->toBeNull()
        ->and($sub->refresh()->trashed_at)->not->toBeNull()
        // Trashed bytes still occupy storage — used_bytes is UNCHANGED.
        ->and($drive->storage()->refresh()->used_bytes)->toBe($usedBefore);
});

it('trash освобождает имя для нового сиблинга (partial-uniq исключает trashed)', function (): void {
    $drive = DriveTestHelpers::drive();

    $first = $drive->createFolder('Docs');
    $drive->trash($first);

    // Same name is now free because the trashed row is excluded from the index.
    $second = $drive->createFolder('Docs');
    expect($second->getKey())->not->toEqual($first->getKey());
});

it('restore очищает trashed-триплет на поддереве', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->createFolder('Project');
    $sub = $drive->createFolder('Sub', $folder);

    $drive->trash($folder);
    $drive->restore($folder);

    expect($folder->refresh()->trashed_at)->toBeNull()
        ->and($sub->refresh()->trashed_at)->toBeNull();
});

it('restore при живом сиблинге с тем же именем → конфликт', function (): void {
    $drive = DriveTestHelpers::drive();

    $original = $drive->createFolder('Docs');
    $drive->trash($original);

    // A live sibling now owns the name.
    $drive->createFolder('Docs');

    expect(fn () => $drive->restore($original))
        ->toThrow(NodeNameConflictException::class);
});

it('restore возвращает к корню, если исходный родитель пропал', function (): void {
    $drive = DriveTestHelpers::drive();

    $parent = $drive->createFolder('Parent');
    $child = $drive->createFolder('Child', $parent);

    // Trash the child (records trashed_from_parent_id = Parent), then make the
    // original home vanish by soft-deleting the parent directly.
    $drive->trash($child);
    $parent->delete();

    $restored = $drive->restore($child->refresh());

    // Re-homed to the storage root node, deterministically.
    expect($restored->parent_id)->toEqual($drive->root()->getKey());
});
