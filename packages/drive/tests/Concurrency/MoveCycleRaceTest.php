<?php

declare(strict_types=1);

use Vaulter\Drive\Actions\Nodes\MoveNodeAction;
use Vaulter\Drive\Data\Nodes\MoveNodeInput;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * Two concurrent moves that would each be legal alone but together close a loop.
 * The cycle check reads the subtree, so it is only sound if no other structural
 * change of the same drive commits between that read and the UPDATE — the drive
 * tree lock provides that. Real concurrency needs PostgreSQL (ForkRace).
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
});

function raceMove(int|string $nodeId, int|string $intoId): Closure
{
    return static fn (): string => (string) app(MoveNodeAction::class)->execute(OperationContext::system(Node::query()->findOrFail($nodeId)->storage, 'test fixture'), new MoveNodeInput(
        node: Node::query()->findOrFail($nodeId),
        into: Node::query()->findOrFail($intoId),
    ))->getKey();
}

/**
 * Walk parent links up from the node; a tree reaches the root within the node count.
 */
function reachesRoot(int|string $nodeId, int|string $rootId): bool
{
    $current = $nodeId;

    for ($step = 0; $step <= Node::query()->count(); $step++) {
        if ((string) $current === (string) $rootId) {
            return true;
        }

        $current = Node::query()->whereKey($current)->value('parent_id');

        if ($current === null) {
            return false;
        }
    }

    return false;
}

/**
 * @param list<RaceOutcome> $outcomes
 */
function assertOneMoveWins(array $outcomes): void
{
    $won = array_values(array_filter($outcomes, static fn (RaceOutcome $outcome): bool => $outcome->ok));
    $lost = array_values(array_filter($outcomes, static fn (RaceOutcome $outcome): bool => !$outcome->ok));

    expect($won)->toHaveCount(1)
        ->and($lost)->toHaveCount(1)
        ->and($lost[0]->failedWith(NodeCycleException::class))->toBeTrue("the losing move failed with {$lost[0]->exception}: {$lost[0]->message}");
}

it('lets exactly one of move(A→B) and move(B→A) win and leaves no cycle', function (): void {
    $drive = DriveTestHelpers::drive();
    $rootId = $drive->root()->id;
    $a = $drive->folders()->create('A')->id;
    $b = $drive->folders()->create('B')->id;

    assertOneMoveWins(ForkRace::run([raceMove($a, $b), raceMove($b, $a)]));

    expect(reachesRoot($a, $rootId))->toBeTrue()
        ->and(reachesRoot($b, $rootId))->toBeTrue();
});

// Row locks on the moved node and its destination alone serialise the two-node race
// above; here the two moves lock disjoint rows, so only the tree lock keeps the
// second cycle check from reading a subtree the first move is about to change.
it('lets exactly one of move(A→B/B1) and move(B→A/A1) win and leaves no cycle', function (): void {
    $drive = DriveTestHelpers::drive();
    $rootId = $drive->root()->id;
    $a = $drive->folders()->create('A');
    $b = $drive->folders()->create('B');
    $a1 = $drive->folders()->create('A1', $a)->id;
    $b1 = $drive->folders()->create('B1', $b)->id;

    assertOneMoveWins(ForkRace::run([raceMove($a->id, $b1), raceMove($b->id, $a1)]));

    expect(reachesRoot($a1, $rootId))->toBeTrue()
        ->and(reachesRoot($b1, $rootId))->toBeTrue();
});
