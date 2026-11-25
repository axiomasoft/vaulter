<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

it('creates a folder at the storage root (under the root node)', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->folders()->create('Docs');

    // "At root" means a child of the storage's root folder node, not a parentless
    // sibling of it — the root node is the top of the tree.
    expect($folder->kind)->toBe(NodeKind::Folder)
        ->and($folder->name)->toBe('Docs')
        ->and($folder->parentId)->toEqual($drive->root()->id)
        ->and($folder->driveId)->toEqual($drive->model()->getKey());
});

it('creates a nested folder under its parent', function (): void {
    $drive = DriveTestHelpers::drive();

    $parent = $drive->folders()->create('Docs');
    $child = $drive->folders()->create('2026', $parent);

    expect($child->parentId)->toEqual($parent->id);
});

it('rejects a sibling name conflict deterministically', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('Docs');

    expect(fn (): NodeSnapshot => $drive->folders()->create('Docs'))
        ->toThrow(NodeNameConflictException::class);
});

it('ignores case when checking siblings', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('Docs');

    expect(fn (): NodeSnapshot => $drive->folders()->create('DOCS'))
        ->toThrow(NodeNameConflictException::class);
});

it('allows the same name under different parents', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');

    $drive->folders()->create('Shared', $a);
    $shared = $drive->folders()->create('Shared', $b);

    expect($shared->parentId)->toEqual($b->id);
});

it('dispatches NodeCreated when a folder is created', function (): void {
    Event::fake([FolderCreated::class]);

    $folder = DriveTestHelpers::drive()->folders()->create('Docs');

    Event::assertDispatched(
        FolderCreated::class,
        fn (FolderCreated $event): bool => $event->node->id === $folder->id,
    );
});

it('list returns the direct children of a folder', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('A');
    $drive->folders()->create('B');

    expect(collect($drive->nodes()->children()->items)->pluck('name')->all())->toBe(['A', 'B']);
});
