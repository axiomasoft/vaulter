<?php

declare(strict_types=1);

use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Tests\Schema\ForceCreates;

/**
 * staudenmeir adjacency relationships resolve over the strategy-driven keys:
 * descendants()/ancestors() must be correct on a small fixture tree
 *
 *   root
 *   └── docs
 *       └── q1
 *           └── report (leaf)
 */
beforeEach(function (): void {
    $this->storage = ForceCreates::make(Drive::class, [
        'owner_type' => 'team',
        'owner_id' => 1,
        'name' => 'Disk',
    ]);

    $make = fn (string $name, ?Node $parent): Node => ForceCreates::make(Node::class, [
        'drive_id' => $this->storage->getKey(),
        'parent_id' => $parent?->getKey(),
        'kind' => NodeKind::Folder,
        'name' => $name,
    ]);

    $this->root = $make('root', null);
    $this->docs = $make('docs', $this->root);
    $this->q1 = $make('q1', $this->docs);
    $this->report = $make('report', $this->q1);
});

it('casts kind to the NodeKind enum', function (): void {
    expect($this->root->kind)->toBe(NodeKind::Folder);
});

it('descendants collects the whole subtree from the root', function (): void {
    $names = $this->root->descendants()->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['docs', 'q1', 'report']);
});

it('ancestors climbs from a leaf to the root', function (): void {
    $names = $this->report->ancestors()->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['docs', 'q1', 'root']);
});

it('children returns the direct descendants', function (): void {
    expect($this->docs->children()->pluck('name')->all())->toBe(['q1']);
});

it('the treeOf scope limits the query to one storage', function (): void {
    $other = ForceCreates::make(Drive::class, [
        'owner_type' => 'team', 'owner_id' => 2, 'name' => 'Other',
    ]);
    ForceCreates::make(Node::class, [
        'drive_id' => $other->getKey(), 'kind' => NodeKind::Folder, 'name' => 'alien',
    ]);

    $count = Node::query()->treeOf($this->storage->getKey())->count();

    expect($count)->toBe(4);
});

it('storage->nodes and node->storage are related', function (): void {
    expect($this->storage->nodes()->count())->toBe(4)
        ->and($this->report->storage->getKey())->toBe($this->storage->getKey());
});
