<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Vaulter\Documents\Models\NodeComment;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The v_node_comments schema (ADR-0014 шаг 1): the table, its columns, the node_id
 * cascade and the parent_id self-FK threading cascade. Additive parity with the
 * legacy v_document_comments — no soft-delete (a delete is permanent).
 */
function makeNodeComment(Node $node, ?NodeComment $parent = null, ?int $tenantId = null): NodeComment
{
    $comment = new NodeComment;
    $comment->forceFill([
        'tenant_id' => $tenantId,
        'node_id' => $node->getKey(),
        'parent_id' => $parent?->getKey(),
        'author_type' => (new TestUser)->getMorphClass(),
        'author_id' => 1,
        'body' => 'hello',
        'anchor' => ['from' => 3, 'to' => 9],
    ])->save();

    return $comment->refresh();
}

it('creates the v_node_comments table with every column', function (): void {
    expect(Schema::hasTable('v_node_comments'))->toBeTrue()
        ->and(Schema::hasColumns('v_node_comments', [
            'id', 'tenant_id', 'node_id', 'parent_id', 'author_type', 'author_id',
            'body', 'anchor', 'resolved_at', 'resolved_by', 'extra_data',
            'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('has no soft-delete column (a delete is permanent — legacy parity)', function (): void {
    expect(Schema::hasColumn('v_node_comments', 'deleted_at'))->toBeFalse();
});

it('persists a comment with a null tenant and a jsonb anchor round-trip', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'commented');

    $comment = makeNodeComment($node);

    expect($comment->tenant_id)->toBeNull()
        ->and($comment->anchor)->toBe(['from' => 3, 'to' => 9])
        ->and($comment->isResolved())->toBeFalse();
});

it('resolves its owning node through the node relation', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'owned');

    $comment = makeNodeComment($node);

    expect($comment->node->getKey())->toBe($node->getKey());
});

it('threads replies under a parent via the parent/replies self-relations', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'threaded');

    $root = makeNodeComment($node);
    $one = makeNodeComment($node, parent: $root);
    $two = makeNodeComment($node, parent: $root);

    expect($one->parent_id)->toBe($root->getKey())
        ->and($one->parent->getKey())->toBe($root->getKey())
        ->and($root->replies()->pluck('id')->all())
        ->toEqualCanonicalizing([$one->getKey(), $two->getKey()]);
});

it('carries a resolution tombstone (resolved_at + resolved_by)', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'resolvable');

    $comment = makeNodeComment($node);
    $comment->forceFill(['resolved_at' => now(), 'resolved_by' => 7])->save();

    $fresh = $comment->fresh();
    expect($fresh->isResolved())->toBeTrue()
        ->and($fresh->resolved_by)->toBe(7);
});
