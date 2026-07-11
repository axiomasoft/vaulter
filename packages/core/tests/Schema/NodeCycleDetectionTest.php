<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Tests\Schema\ForceCreates;

/**
 * F-P4.2-02 — `Node` did not override `enableCycleDetection()` (trait default
 * `false`): the only guard against a `parent_id` cycle was
 * `MoveNodeAction::assertNoCycle()`, exercised ONLY on the move-through-Action
 * write path. A cycle that reaches the data any other way (raw SQL, a seeder,
 * a legacy import) went undetected on READ — `ancestors()`/`descendants()`
 * would recurse without a stop condition.
 *
 * The fixture below forges a 2-node cycle with a raw `DB::table()->update()`
 * — deliberately bypassing `MoveNodeAction` — to simulate exactly that "cycle
 * reached the data outside the Action" scenario.
 */
beforeEach(function (): void {
    $this->storage = ForceCreates::make(Storage::class, [
        'owner_type' => 'team',
        'owner_id' => 1,
        'name' => 'Disk',
    ]);

    $this->root = ForceCreates::make(Node::class, [
        'storage_id' => $this->storage->getKey(),
        'parent_id' => null,
        'kind' => NodeKind::Folder,
        'name' => 'root',
    ]);

    $this->child = ForceCreates::make(Node::class, [
        'storage_id' => $this->storage->getKey(),
        'parent_id' => $this->root->getKey(),
        'kind' => NodeKind::Folder,
        'name' => 'child',
    ]);

    // Forge the cycle OUTSIDE MoveNodeAction: root's parent becomes its own
    // child — a raw write no Action-level assertNoCycle() ever sees.
    DB::table('v_nodes')
        ->where('id', $this->root->getKey())
        ->update(['parent_id' => $this->child->getKey()]);
});

it('обход предков по узлу с форсированным циклом завершается ограниченно, не раздутой/бесконечной рекурсией [F-P4.2-02]', function (): void {
    $this->root->refresh();

    $ancestors = $this->root->ancestors()->get();

    // Cycle detection stops the recursive leg at the repeated row: the
    // traversal terminates with a small, bounded result — never an unbounded
    // scan and never an engine crash.
    expect($ancestors->count())->toBeLessThanOrEqual(2);
});

it('обход потомков по узлу с форсированным циклом завершается ограниченно, не раздутой/бесконечной рекурсией [F-P4.2-02]', function (): void {
    $this->child->refresh();

    $descendants = $this->child->descendants()->get();

    expect($descendants->count())->toBeLessThanOrEqual(2);
});
