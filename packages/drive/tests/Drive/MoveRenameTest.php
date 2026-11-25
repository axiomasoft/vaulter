<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

it('move is ONE UPDATE of parent_id; the subtree is not touched', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');
    $child = $drive->folders()->create('child', $a);
    $grandchild = $drive->folders()->create('grandchild', $child);

    DB::enableQueryLog();
    $drive->nodes()->move($child, $b);
    $updates = array_filter(DB::getQueryLog(), fn (array $q): bool => str_starts_with(strtolower($q['query']), 'update') && str_contains(strtolower($q['query']), 'parent_id'));
    DB::disableQueryLog();

    expect(DriveTestHelpers::model($child)->parent_id)->toEqual($b->id)
        // Descendants ride along untouched — exactly one row was re-parented.
        ->and(DriveTestHelpers::model($grandchild)->parent_id)->toEqual($child->id)
        ->and(count($updates))->toBe(1)
        ->and(DriveTestHelpers::model($child)->sequence)->toBe(1)
        ->and(DriveTestHelpers::model($grandchild)->sequence)->toBe(0);
});

it('cycle guard: a node cannot move into itself', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->folders()->create('A');

    expect(fn (): NodeSnapshot => $drive->nodes()->move($folder, $folder))
        ->toThrow(NodeCycleException::class);
});

it('cycle guard: a node cannot move into its own descendant', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $child = $drive->folders()->create('child', $a);

    expect(fn (): NodeSnapshot => $drive->nodes()->move($a, $child))
        ->toThrow(NodeCycleException::class);
});

it('cycle guard: when a descendant id is a suffix of the ancestor id, the move into it still fails', function (): void {
    $drive = DriveTestHelpers::drive();
    $rootId = $drive->model()->root_node_id;
    $storageId = $drive->model()->getKey();
    $make = static function (int $id, int|string|null $parentId, string $name) use ($storageId): Node {
        $node = new Node;
        $node->forceFill([
            'id' => $id,
            'drive_id' => $storageId,
            'parent_id' => $parentId,
            'kind' => NodeKind::Folder,
            'name' => $name,
        ])->save();

        return $node->refresh();
    };
    // 2100 → 50 → 100. The path "2100.50" contains "100.", so the CTE cycle guard drops 100.
    $ancestor = $make(2100, $rootId, 'ancestor');
    $make(50, 2100, 'middle');
    $descendant = $make(100, 50, 'descendant');

    expect(fn () => app(MoveNodeAction::class)->execute(
        DriveContext::system($drive->model(), 'suffix id cycle'),
        new MoveNodeInput(node: $ancestor, into: $descendant),
    ))->toThrow(NodeCycleException::class);

    expect($ancestor->refresh()->parent_id)->toEqual($rootId)
        ->and($descendant->refresh()->parent_id)->toEqual(50);
});

it('move rejects a name conflict at the destination', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');
    $drive->folders()->create('dup', $a);
    $moving = $drive->folders()->create('dup', $b);

    // $moving is "dup" under B; A already has a live "dup".
    expect(fn (): NodeSnapshot => $drive->nodes()->move($moving, $a))
        ->toThrow(NodeNameConflictException::class);
});

it('move rejects a cross-storage move', function (): void {
    $driveA = DriveTestHelpers::drive();
    $driveB = DriveTestHelpers::drive();

    $node = $driveA->folders()->create('A');
    $foreignParent = $driveB->folders()->create('B');

    expect(fn (): NodeSnapshot => $driveA->nodes()->move($node, $foreignParent))
        ->toThrow(NodeNotFoundException::class);
});

it('rename changes the name and checks for sibling conflicts', function (): void {
    $drive = DriveTestHelpers::drive();

    $a = $drive->folders()->create('A');
    $drive->folders()->create('B');

    $renamed = $drive->nodes()->rename($a, 'Renamed');
    expect($renamed->name)->toBe('Renamed');

    expect(fn (): NodeSnapshot => $drive->nodes()->rename($renamed, 'B'))
        ->toThrow(NodeNameConflictException::class);
});

it('renaming to the same name is a no-op without a conflict', function (): void {
    $drive = DriveTestHelpers::drive();
    $a = $drive->folders()->create('A');

    expect($drive->nodes()->rename($a, 'A')->name)->toBe('A');
});
