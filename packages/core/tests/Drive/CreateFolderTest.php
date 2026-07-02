<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Drive\Events\NodeCreated;
use Vaulter\Drive\Exceptions\NodeNameConflictException;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Tests\Drive\DriveTestHelpers;

it('создаёт папку в корне хранилища (под root-узлом)', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->createFolder('Docs');

    // "At root" means a child of the storage's root folder node, not a parentless
    // sibling of it — the root node is the top of the tree.
    expect($folder->kind)->toBe(NodeKind::Folder)
        ->and($folder->name)->toBe('Docs')
        ->and($folder->parent_id)->toEqual($drive->root()->getKey())
        ->and($folder->storage_id)->toEqual($drive->storage()->getKey());
});

it('создаёт вложенную папку под родителем', function (): void {
    $drive = DriveTestHelpers::drive();

    $parent = $drive->createFolder('Docs');
    $child = $drive->createFolder('2026', $parent);

    expect($child->parent_id)->toEqual($parent->getKey());
});

it('детерминированно отклоняет конфликт имён сиблингов', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->createFolder('Docs');

    expect(fn () => $drive->createFolder('Docs'))
        ->toThrow(NodeNameConflictException::class);
});

it('игнорирует регистр при проверке сиблингов', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->createFolder('Docs');

    expect(fn () => $drive->createFolder('DOCS'))
        ->toThrow(NodeNameConflictException::class);
});

it('разрешает одинаковые имена в разных родителях', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->createFolder('A');
    $b = $drive->createFolder('B');

    $drive->createFolder('Shared', $a);
    $shared = $drive->createFolder('Shared', $b);

    expect($shared->parent_id)->toEqual($b->getKey());
});

it('диспатчит NodeCreated при создании папки', function (): void {
    Event::fake([NodeCreated::class]);

    $folder = DriveTestHelpers::drive()->createFolder('Docs');

    Event::assertDispatched(
        NodeCreated::class,
        fn (NodeCreated $event): bool => $event->node->getKey() === $folder->getKey(),
    );
});

it('list возвращает прямых детей папки', function (): void {
    $drive = DriveTestHelpers::drive();

    $drive->createFolder('A');
    $drive->createFolder('B');

    expect($drive->list()->pluck('name')->all())->toBe(['A', 'B']);
});
