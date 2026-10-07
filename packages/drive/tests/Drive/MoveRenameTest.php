<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Tests\Drive\DriveTestHelpers;

it('move = ОДИН UPDATE по parent_id, поддерево не трогается', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');
    $child = $drive->folders()->create('child', $a);
    $grandchild = $drive->folders()->create('grandchild', $child);

    DB::enableQueryLog();
    $drive->nodes()->move($child, $b);
    $updates = array_filter(DB::getQueryLog(), fn (array $q): bool => str_starts_with(strtolower($q['query']), 'update') && str_contains(strtolower($q['query']), 'parent_id'));
    DB::disableQueryLog();

    expect($child->refresh()->parent_id)->toEqual($b->getKey())
        // Descendants ride along untouched — exactly one row was re-parented.
        ->and($grandchild->refresh()->parent_id)->toEqual($child->getKey())
        ->and(count($updates))->toBe(1)
        ->and($child->sequence)->toBe(1)
        ->and($grandchild->sequence)->toBe(0);
});

it('цикл-гард: запрет перемещения в самого себя', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('A');

    expect(fn () => $drive->nodes()->move($folder, $folder))
        ->toThrow(NodeCycleException::class);
});

it('цикл-гард: запрет перемещения в собственного потомка', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $child = $drive->folders()->create('child', $a);

    expect(fn () => $drive->nodes()->move($a, $child))
        ->toThrow(NodeCycleException::class);
});

it('move отклоняет конфликт имён в месте назначения', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');
    $drive->folders()->create('dup', $a);
    $moving = $drive->folders()->create('dup', $b);

    // $moving is "dup" under B; A already has a live "dup".
    expect(fn () => $drive->nodes()->move($moving, $a))
        ->toThrow(NodeNameConflictException::class);
});

it('move отклоняет кросс-storage перемещение', function (): void {
    $driveA = DriveTestHelpers::drive();
    $driveB = DriveTestHelpers::drive();

    $node = $driveA->folders()->create('A');
    $foreignParent = $driveB->folders()->create('B');

    expect(fn () => $driveA->nodes()->move($node, $foreignParent))
        ->toThrow(NodeNotFoundException::class);
});

it('rename меняет имя и проверяет конфликт сиблингов', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $drive->folders()->create('B');

    $renamed = $drive->nodes()->rename($a, 'Renamed');
    expect($renamed->name)->toBe('Renamed');

    expect(fn () => $drive->nodes()->rename($renamed, 'B'))
        ->toThrow(NodeNameConflictException::class);
});

it('rename в то же имя — no-op без конфликта', function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->folders()->create('A');

    expect($drive->nodes()->rename($a, 'A')->name)->toBe('A');
});
