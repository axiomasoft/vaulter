<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Exceptions\CrossStorageMoveException;
use Vaulter\Drive\Exceptions\CycleException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Tests\Drive\DriveTestHelpers;

it('move = ОДИН UPDATE по parent_id, поддерево не трогается', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->createFolder('A');
    $b = $drive->createFolder('B');
    $child = $drive->createFolder('child', $a);
    $grandchild = $drive->createFolder('grandchild', $child);

    DB::enableQueryLog();
    $drive->move($child, $b);
    $updates = array_filter(DB::getQueryLog(), fn (array $q): bool => str_starts_with(strtolower($q['query']), 'update'));
    DB::disableQueryLog();

    expect($child->refresh()->parent_id)->toEqual($b->getKey())
        // Descendants ride along untouched — exactly one row was re-parented.
        ->and($grandchild->refresh()->parent_id)->toEqual($child->getKey())
        ->and(count($updates))->toBe(1);
});

it('цикл-гард: запрет перемещения в самого себя', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->createFolder('A');

    expect(fn () => $drive->move($folder, $folder))
        ->toThrow(CycleException::class);
});

it('цикл-гард: запрет перемещения в собственного потомка', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->createFolder('A');
    $child = $drive->createFolder('child', $a);

    expect(fn () => $drive->move($a, $child))
        ->toThrow(CycleException::class);
});

it('move отклоняет конфликт имён в месте назначения', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->createFolder('A');
    $b = $drive->createFolder('B');
    $drive->createFolder('dup', $a);
    $moving = $drive->createFolder('dup', $b);

    // $moving is "dup" under B; A already has a live "dup".
    expect(fn () => $drive->move($moving, $a))
        ->toThrow(NodeNameConflictException::class);
});

it('move отклоняет кросс-storage перемещение', function (): void {
    $driveA = DriveTestHelpers::drive();
    $driveB = DriveTestHelpers::drive();

    $node = $driveA->createFolder('A');
    $foreignParent = $driveB->createFolder('B');

    expect(fn () => $driveA->move($node, $foreignParent))
        ->toThrow(CrossStorageMoveException::class);
});

it('rename меняет имя и проверяет конфликт сиблингов', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->createFolder('A');
    $drive->createFolder('B');

    $renamed = $drive->rename($a, 'Renamed');
    expect($renamed->name)->toBe('Renamed');

    expect(fn () => $drive->rename($renamed, 'B'))
        ->toThrow(NodeNameConflictException::class);
});

it('rename в то же имя — no-op без конфликта', function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->createFolder('A');

    expect($drive->rename($a, 'A')->name)->toBe('A');
});
