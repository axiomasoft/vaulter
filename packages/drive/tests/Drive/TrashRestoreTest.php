<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\RestoreOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Enums\Drive\OnNameConflict;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('trash помечает узел и всё поддерево, used_bytes не меняется', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->folders()->create('Project');
    $sub = $drive->folders()->create('Sub', $folder);
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes here'), 'f.bin'), new UploadOptions(parent: $sub));

    $usedBefore = $drive->model()->refresh()->used_bytes;
    expect($usedBefore)->toBe(10);

    $drive->nodes()->trash($folder);

    // The whole subtree is trashed atomically.
    expect($folder->refresh()->trashed_at)->not->toBeNull()
        ->and($sub->refresh()->trashed_at)->not->toBeNull()
        // Trashed bytes still occupy storage — used_bytes is UNCHANGED.
        ->and($drive->model()->refresh()->used_bytes)->toBe($usedBefore);
});

it('trash освобождает имя для нового сиблинга (partial-uniq исключает trashed)', function (): void {
    $drive = DriveTestHelpers::drive();

    $first = $drive->folders()->create('Docs');
    $drive->nodes()->trash($first);

    // Same name is now free because the trashed row is excluded from the index.
    $second = $drive->folders()->create('Docs');
    expect($second->getKey())->not->toEqual($first->getKey());
});

it('restore очищает trashed-триплет на поддереве', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->folders()->create('Project');
    $sub = $drive->folders()->create('Sub', $folder);

    $drive->nodes()->trash($folder);
    $drive->trash()->restore($folder);

    expect($folder->refresh()->trashed_at)->toBeNull()
        ->and($sub->refresh()->trashed_at)->toBeNull();
});

it('restore при живом сиблинге с тем же именем → конфликт', function (): void {
    $drive = DriveTestHelpers::drive();

    $original = $drive->folders()->create('Docs');
    $drive->nodes()->trash($original);

    // A live sibling now owns the name.
    $drive->folders()->create('Docs');

    expect(fn () => $drive->trash()->restore($original, new RestoreOptions(onConflict: OnNameConflict::Fail)))
        ->toThrow(NodeNameConflictException::class);
});

it('restore возвращает к корню, если исходный родитель пропал', function (): void {
    $drive = DriveTestHelpers::drive();

    $parent = $drive->folders()->create('Parent');
    $child = $drive->folders()->create('Child', $parent);

    // Trash the child (records trashed_from_parent_id = Parent), then make the
    // original home vanish by soft-deleting the parent directly.
    $drive->nodes()->trash($child);
    $parent->delete();

    $restored = $drive->trash()->restore($child->refresh());

    // Re-homed to the storage root node, deterministically.
    expect($restored->parent_id)->toEqual($drive->root()->getKey());
});
