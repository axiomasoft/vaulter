<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Vaulter\Documents\Models\NodeComment;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The NODE-attached comment HTTP surface (ADR-0014 шаг 1), the additive twin of the
 * legacy document_id-bound CommentController. Authorization runs through the real
 * database grant resolver: read ≥ View, create = Comment (both the Read bit), and
 * resolve/delete = the AUTHOR of the comment OR Manage on the node. These tests pin
 * the author-or-Manage boundary that a green happy-path would miss.
 */
$user = static fn (int $id): TestUser => AccessTestHelpers::user($id);

$commentNode = static function (GrantLevel $level, int $subjectId = 1, string $name = 'commentable'): array {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, $name);
    AccessTestHelpers::grant($node, $level, subjectId: $subjectId);

    return [$storage, $node];
};

$url = static fn (Node $node): string => 'api/vaulter/nodes/'.$node->getKey().'/comments';

// A viewer/commenter shares the Read bit, so a Reader can both list and create.
it('lists the node comment thread for a viewer', function () use ($user, $commentNode, $url): void {
    [, $node] = $commentNode(GrantLevel::Reader);
    $this->actingAs($user(1));

    $this->postJson($url($node), ['body' => 'first'])->assertCreated();
    $this->postJson($url($node), ['body' => 'second'])->assertCreated();

    $response = $this->getJson($url($node))->assertOk();
    expect($response->json('data'))->toHaveCount(2)
        ->and(collect($response->json('data'))->pluck('body')->all())
        ->toEqualCanonicalizing(['first', 'second']);
});

it('403s the thread listing without any grant', function () use ($user): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'locked');
    $this->actingAs($user(1));

    $this->getJson('api/vaulter/nodes/'.$node->getKey().'/comments')->assertStatus(403);
});

it('creates a comment with the REGISTERED morph alias, not a raw FQCN', function () use ($user, $url): void {
    // With an enforced morph map the author_type is the alias 'user' — proof the code
    // stores $user->getMorphClass(), not $user::class (which would be the FQCN).
    Relation::morphMap(['user' => TestUser::class]);

    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'aliased');
    AccessTestHelpers::grant($node, GrantLevel::Commenter, subjectId: 1);
    $this->actingAs($user(1));

    $response = $this->postJson($url($node), [
        'body' => 'aliased author',
        'anchor' => ['from' => 2, 'to' => 5],
    ])->assertCreated();

    $response->assertJsonPath('data.author.type', 'user');
    expect(NodeComment::query()->firstOrFail()->author_type)->toBe('user');
});

it('403s creating a comment without a grant', function () use ($user, $url): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'nogrant');
    $this->actingAs($user(1));

    $this->postJson($url($node), ['body' => 'nope'])->assertStatus(403);
});

it('threads a reply and rejects a cross-node parent', function () use ($user, $commentNode, $url): void {
    [$storage, $nodeA] = $commentNode(GrantLevel::Commenter, name: 'thread-a');
    $nodeB = AccessTestHelpers::folder($storage, null, 'thread-b');
    AccessTestHelpers::grant($nodeB, GrantLevel::Commenter, subjectId: 1);
    $this->actingAs($user(1));

    $root = $this->postJson($url($nodeA), ['body' => 'root'])->assertCreated()->json('data.id');

    $this->postJson($url($nodeA), ['body' => 'reply', 'parent_id' => $root])
        ->assertCreated()
        ->assertJsonPath('data.parent_id', $root);

    // A reply on node B pointing at node A's comment is a 404 — no cross-node thread.
    $this->postJson($url($nodeB), ['body' => 'stolen', 'parent_id' => $root])->assertNotFound();
});

it('lets the AUTHOR resolve and delete their own comment', function () use ($user, $commentNode, $url): void {
    [, $node] = $commentNode(GrantLevel::Commenter);
    $this->actingAs($user(1));

    $id = $this->postJson($url($node), ['body' => 'mine'])->assertCreated()->json('data.id');

    $this->postJson($url($node).'/'.$id.'/resolve')
        ->assertOk()
        ->assertJsonPath('data.resolved_by', 1);

    expect(NodeComment::query()->findOrFail($id)->isResolved())->toBeTrue();

    $this->deleteJson($url($node).'/'.$id)->assertNoContent();
    expect(NodeComment::query()->whereKey($id)->exists())->toBeFalse();
});

it('403s a non-author WITHOUT Manage on resolve and delete', function () use ($user, $commentNode, $url): void {
    [, $node] = $commentNode(GrantLevel::Commenter, subjectId: 1);
    // A second actor with only the comment bit — neither the author nor a manager.
    AccessTestHelpers::grant($node, GrantLevel::Commenter, subjectId: 2);

    $this->actingAs($user(1));
    $id = $this->postJson($url($node), ['body' => 'authored by 1'])->assertCreated()->json('data.id');

    $this->actingAs($user(2));
    $this->postJson($url($node).'/'.$id.'/resolve')->assertStatus(403);
    $this->deleteJson($url($node).'/'.$id)->assertStatus(403);

    // The comment survived both refused attempts.
    expect(NodeComment::query()->findOrFail($id)->isResolved())->toBeFalse();
});

it('lets a MANAGER who is not the author resolve and delete', function () use ($user, $commentNode, $url): void {
    [, $node] = $commentNode(GrantLevel::Commenter, subjectId: 1);
    AccessTestHelpers::grant($node, GrantLevel::Owner, subjectId: 3); // Owner ⇒ Manage

    $this->actingAs($user(1));
    $id = $this->postJson($url($node), ['body' => 'authored by 1'])->assertCreated()->json('data.id');

    $this->actingAs($user(3));
    $this->postJson($url($node).'/'.$id.'/resolve')->assertOk();
    $this->deleteJson($url($node).'/'.$id)->assertNoContent();

    expect(NodeComment::query()->whereKey($id)->exists())->toBeFalse();
});

afterEach(function (): void {
    // The morph-alias test enforces a global map; clear it so it never leaks into
    // another test file.
    Relation::morphMap([], false);
});
