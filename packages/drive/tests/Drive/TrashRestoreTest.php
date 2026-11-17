<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Exceptions\Nodes\NodeTrashedByAncestorException;
use Vaulter\Drive\Exceptions\Nodes\RestoreDestinationRequiredException;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

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
    expect(DriveTestHelpers::model($folder)->trashed_at)->not->toBeNull()
        ->and(DriveTestHelpers::model($sub)->trashed_at)->not->toBeNull()
        // Trashed bytes still occupy storage — used_bytes is UNCHANGED.
        ->and($drive->model()->refresh()->used_bytes)->toBe($usedBefore);
});

it('trash освобождает имя для нового сиблинга (partial-uniq исключает trashed)', function (): void {
    $drive = DriveTestHelpers::drive();

    $first = $drive->folders()->create('Docs');
    $drive->nodes()->trash($first);

    // Same name is now free because the trashed row is excluded from the index.
    $second = $drive->folders()->create('Docs');
    expect($second->id)->not->toEqual($first->id);
});

it('restore очищает trashed-триплет на поддереве', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->folders()->create('Project');
    $sub = $drive->folders()->create('Sub', $folder);

    $drive->nodes()->trash($folder);
    $drive->trash()->restore($folder);

    expect(DriveTestHelpers::model($folder)->trashed_at)->toBeNull()
        ->and(DriveTestHelpers::model($sub)->trashed_at)->toBeNull();
});

it('restore при живом сиблинге с тем же именем → конфликт', function (): void {
    $drive = DriveTestHelpers::drive();

    $original = $drive->folders()->create('Docs');
    $drive->nodes()->trash($original);

    // A live sibling now owns the name.
    $drive->folders()->create('Docs');

    expect(fn (): NodeSnapshot => $drive->trash()->restore($original, new RestoreOptions(onConflict: OnNameConflict::Fail)))
        ->toThrow(NodeNameConflictException::class);
});

it('restore без назначения отказывает, если исходный родитель пропал, и не переносит в корень', function (): void {
    $drive = DriveTestHelpers::drive();

    $parent = $drive->folders()->create('Parent');
    $child = $drive->folders()->create('Child', $parent);

    // Trash the child (records trashed_from_parent_id = Parent), then trash the
    // original home too: it is no longer a live folder.
    $drive->nodes()->trash($child);
    $drive->nodes()->trash($parent);

    expect(fn (): NodeSnapshot => $drive->trash()->restore($child))->toThrow(RestoreDestinationRequiredException::class)
        ->and(DriveTestHelpers::model($child)->trashed_at)->not->toBeNull();

    $restored = $drive->trash()->restore($child, new RestoreOptions(destination: $drive->root()));
    expect($restored->parentId)->toEqual($drive->root()->id);
});

it('restore возвращает только своё поддерево: ранее удалённый потомок остаётся в корзине своим корнем', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('Project');
    $early = $drive->folders()->create('Early', $folder);
    $member = $drive->folders()->create('Member', $folder);

    $drive->nodes()->trash($early);
    $drive->nodes()->trash($folder);

    expect(fn (): NodeSnapshot => $drive->trash()->restore($member))->toThrow(NodeTrashedByAncestorException::class);

    $drive->trash()->restore($folder);

    expect(DriveTestHelpers::model($folder)->trashed_at)->toBeNull()
        ->and(DriveTestHelpers::model($member)->trashed_at)->toBeNull()
        ->and(DriveTestHelpers::model($member)->trash_root_id)->toBeNull()
        ->and(DriveTestHelpers::model($early)->trashed_at)->not->toBeNull()
        ->and((string) DriveTestHelpers::model($early)->trash_root_id)->toBe($early->id);
    expect(array_map(static fn ($node) => $node->id, $drive->trash()->list()->items))->toBe([$early->id]);
});
