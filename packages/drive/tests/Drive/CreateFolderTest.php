<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

it('создаёт папку в корне хранилища (под root-узлом)', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->folders()->create('Docs');

    // "At root" means a child of the storage's root folder node, not a parentless
    // sibling of it — the root node is the top of the tree.
    expect($folder->kind)->toBe(NodeKind::Folder)
        ->and($folder->name)->toBe('Docs')
        ->and($folder->parent_id)->toEqual($drive->root()->getKey())
        ->and($folder->drive_id)->toEqual($drive->model()->getKey());
});

it('создаёт вложенную папку под родителем', function (): void {
    $drive = DriveTestHelpers::drive();

    $parent = $drive->folders()->create('Docs');
    $child = $drive->folders()->create('2026', $parent);

    expect($child->parent_id)->toEqual($parent->getKey());
});

it('детерминированно отклоняет конфликт имён сиблингов', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('Docs');

    expect(fn (): Node => $drive->folders()->create('Docs'))
        ->toThrow(NodeNameConflictException::class);
});

it('игнорирует регистр при проверке сиблингов', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('Docs');

    expect(fn (): Node => $drive->folders()->create('DOCS'))
        ->toThrow(NodeNameConflictException::class);
});

it('разрешает одинаковые имена в разных родителях', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');

    $drive->folders()->create('Shared', $a);
    $shared = $drive->folders()->create('Shared', $b);

    expect($shared->parent_id)->toEqual($b->getKey());
});

it('диспатчит NodeCreated при создании папки', function (): void {
    Event::fake([FolderCreated::class]);

    $folder = DriveTestHelpers::drive()->folders()->create('Docs');

    Event::assertDispatched(
        FolderCreated::class,
        fn (FolderCreated $event): bool => $event->node->getKey() === $folder->getKey(),
    );
});

it('list возвращает прямых детей папки', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->folders()->create('A');
    $drive->folders()->create('B');

    expect(collect($drive->nodes()->children()->items)->pluck('name')->all())->toBe(['A', 'B']);
});
