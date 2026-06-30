<?php

declare(strict_types=1);

use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;

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
    $this->storage = Storage::query()->create([
        'owner_type' => 'team',
        'owner_id' => 1,
        'name' => 'Disk',
    ]);

    $make = fn (string $name, ?Node $parent): Node => Node::query()->create([
        'storage_id' => $this->storage->getKey(),
        'parent_id' => $parent?->getKey(),
        'kind' => NodeKind::Folder,
        'name' => $name,
    ]);

    $this->root = $make('root', null);
    $this->docs = $make('docs', $this->root);
    $this->q1 = $make('q1', $this->docs);
    $this->report = $make('report', $this->q1);
});

it('каст kind в NodeKind enum', function (): void {
    expect($this->root->kind)->toBe(NodeKind::Folder);
});

it('descendants собирает всё поддерево от корня', function (): void {
    $names = $this->root->descendants()->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['docs', 'q1', 'report']);
});

it('ancestors поднимается до корня от листа', function (): void {
    $names = $this->report->ancestors()->pluck('name')->sort()->values()->all();

    expect($names)->toBe(['docs', 'q1', 'root']);
});

it('children даёт прямых потомков', function (): void {
    expect($this->docs->children()->pluck('name')->all())->toBe(['q1']);
});

it('scope treeOf ограничивает выборку одним хранилищем', function (): void {
    $other = Storage::query()->create([
        'owner_type' => 'team', 'owner_id' => 2, 'name' => 'Other',
    ]);
    Node::query()->create([
        'storage_id' => $other->getKey(), 'kind' => NodeKind::Folder, 'name' => 'alien',
    ]);

    $count = Node::query()->treeOf($this->storage->getKey())->count();

    expect($count)->toBe(4);
});

it('storage->nodes и node->storage связаны', function (): void {
    expect($this->storage->nodes()->count())->toBe(4)
        ->and($this->report->storage->getKey())->toBe($this->storage->getKey());
});
