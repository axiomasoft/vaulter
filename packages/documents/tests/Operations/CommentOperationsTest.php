<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Profiles\Profile;
use Vaulter\Profiles\ProfileRegistry;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * PLAN2.P4.8: CommentOperations. Read needs View, write needs Comment; resolve needs
 * Comment and authorship or Edit; delete needs authorship or Manage. A comment id is
 * resolved only inside the addressed node.
 */

/** @return array{DriveHandle, NodeSnapshot} */
function commentsFixture(string $name = 'thread'): array
{
    $handle = DocumentTestHelpers::drive();

    return [$handle, $handle->folders()->create($name)];
}

function commenter(DriveHandle $handle, NodeSnapshot $node, string $role, int $id): DriveHandle
{
    AccessTestHelpers::grant($node, $role, subjectId: $id);

    return $handle->actingAs(AccessTestHelpers::user($id));
}

afterEach(fn () => Relation::morphMap([], false));

it('adds a comment authored by the acting user and returns a snapshot', function (): void {
    [$handle, $node] = commentsFixture();
    $alice = commenter($handle, $node, RoleRegistry::COMMENTER, 1);

    $comment = Documents::comments($alice)->add($node->id, new AddCommentInput('first', anchor: ['from' => 1, 'to' => 3]));

    expect($comment)->toBeInstanceOf(CommentSnapshot::class)
        ->and($comment->nodeId)->toBe($node->id)
        ->and($comment->authorType)->toBe((new TestUser)->getMorphClass())
        ->and($comment->authorId)->toBe('1')
        ->and($comment->anchor)->toBe(['from' => 1, 'to' => 3])
        ->and($comment->resolvedAt)->toBeNull();
});

it('records the registered morph alias as the author type', function (): void {
    Relation::morphMap(['user' => TestUser::class]);
    [$handle, $node] = commentsFixture();

    $comment = Documents::comments(commenter($handle, $node, RoleRegistry::COMMENTER, 1))->add($node->id, new AddCommentInput('aliased'));

    expect($comment->authorType)->toBe('user');
});

it('refuses a comment from the system: nobody to attribute it to', function (): void {
    [$handle, $node] = commentsFixture();

    expect(fn () => Documents::comments($handle)->add($node->id, new AddCommentInput('system')))->toThrow(MissingActorException::class);
});

it('lists a thread oldest first in pages', function (): void {
    [$handle, $node] = commentsFixture();
    $alice = commenter($handle, $node, RoleRegistry::COMMENTER, 1);
    foreach (['one', 'two', 'three'] as $body) {
        Documents::comments($alice)->add($node->id, new AddCommentInput($body));
    }

    $first = Documents::comments($alice)->list($node->id, new ListOptions(perPage: 2));
    $second = Documents::comments($alice)->list($node->id, new ListOptions(perPage: 2, cursor: $first->nextCursor));

    expect($first)->toBeInstanceOf(Page::class)
        ->and(array_map(static fn (CommentSnapshot $c): string => $c->body, $first->items))->toBe(['one', 'two'])
        ->and($first->nextCursor)->toBeString()
        ->and(array_map(static fn (CommentSnapshot $c): string => $c->body, $second->items))->toBe(['three'])
        ->and($second->nextCursor)->toBeNull();
});

it('threads a reply only under a comment of the same node', function (): void {
    [$handle, $node] = commentsFixture('a');
    $other = $handle->folders()->create('b');
    $alice = commenter($handle, $node, RoleRegistry::COMMENTER, 1);
    AccessTestHelpers::grant($other, RoleRegistry::COMMENTER, subjectId: 1);
    $root = Documents::comments($alice)->add($node->id, new AddCommentInput('root'));

    $reply = Documents::comments($alice)->add($node->id, new AddCommentInput('reply', parentId: $root->id));

    expect($reply->parentId)->toBe($root->id)
        ->and(fn () => Documents::comments($alice)->add($other->id, new AddCommentInput('stolen', parentId: $root->id)))
        ->toThrow(CommentNotFoundException::class);
});

it('resolves a comment of another node as comment_not_found', function (): void {
    [$handle, $node] = commentsFixture('a');
    $other = $handle->folders()->create('b');
    $owner = commenter($handle, $node, RoleRegistry::OWNER, 1);
    AccessTestHelpers::grant($other, RoleRegistry::OWNER, subjectId: 1);
    $comment = Documents::comments($owner)->add($node->id, new AddCommentInput('mine'));

    expect(fn () => Documents::comments($owner)->resolve($other->id, $comment->id))->toThrow(CommentNotFoundException::class)
        ->and(fn () => Documents::comments($owner)->delete($other->id, $comment))->toThrow(CommentNotFoundException::class)
        ->and(NodeComment::query()->whereKey($comment->id)->firstOrFail()->isResolved())->toBeFalse();
});

it('lets the author resolve with Comment, refuses a commenter who is not the author', function (): void {
    [$handle, $node] = commentsFixture();
    $alice = commenter($handle, $node, RoleRegistry::COMMENTER, 1);
    $bob = commenter($handle, $node, RoleRegistry::COMMENTER, 2);
    $comment = Documents::comments($alice)->add($node->id, new AddCommentInput('by alice'));

    expect(fn () => Documents::comments($bob)->resolve($node->id, $comment->id))->toThrow(PermissionDeniedException::class);

    $resolved = Documents::comments($alice)->resolve($node->id, $comment->id);

    expect($resolved->resolvedAt)->not->toBeNull()
        ->and($resolved->resolvedBy)->toBe('1');
});

it('lets an editor who is not the author resolve', function (): void {
    [$handle, $node] = commentsFixture();
    $alice = commenter($handle, $node, RoleRegistry::COMMENTER, 1);
    $editor = commenter($handle, $node, RoleRegistry::WRITER, 3);
    $comment = Documents::comments($alice)->add($node->id, new AddCommentInput('by alice'));

    expect(Documents::comments($editor)->resolve($node->id, $comment)->resolvedBy)->toBe('3');
});

it('refuses resolve to an author who no longer holds Comment', function (): void {
    [$handle, $node] = commentsFixture();
    $grant = AccessTestHelpers::grant($node, RoleRegistry::COMMENTER, subjectId: 1);
    $alice = $handle->actingAs(AccessTestHelpers::user(1));
    $comment = Documents::comments($alice)->add($node->id, new AddCommentInput('by alice'));
    app(VaulterDatabase::class)->write(static fn () => $grant->delete());
    AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 1);

    expect(fn () => Documents::comments($alice)->resolve($node->id, $comment->id))->toThrow(PermissionDeniedException::class);
});

it('deletes for the author or a manager only', function (): void {
    [$handle, $node] = commentsFixture();
    $alice = commenter($handle, $node, RoleRegistry::COMMENTER, 1);
    $editor = commenter($handle, $node, RoleRegistry::WRITER, 3);
    $owner = commenter($handle, $node, RoleRegistry::OWNER, 4);
    $first = Documents::comments($alice)->add($node->id, new AddCommentInput('one'));
    $second = Documents::comments($alice)->add($node->id, new AddCommentInput('two'));

    expect(fn () => Documents::comments($editor)->delete($node->id, $first->id))->toThrow(PermissionDeniedException::class);

    Documents::comments($alice)->delete($node->id, $first->id);
    Documents::comments($owner)->delete($node->id, $second);

    expect(NodeComment::query()->where('node_id', $node->id)->exists())->toBeFalse();
});

it('applies the drive profile comments feature to every method', function (): void {
    [$handle, $node] = commentsFixture();
    $owner = commenter($handle, $node, RoleRegistry::OWNER, 1);
    $comment = Documents::comments($owner)->add($node->id, new AddCommentInput('before'));
    $registry = new ProfileRegistry;
    $registry->register((new Profile)->key('default')->comments(false));
    $registry->freeze();
    app()->instance(ProfileRegistry::class, $registry);

    expect(fn () => Documents::comments($owner)->list($node->id))->toThrow(ProfileRestrictionException::class)
        ->and(fn () => Documents::comments($owner)->add($node->id, new AddCommentInput('after')))->toThrow(ProfileRestrictionException::class)
        ->and(fn () => Documents::comments($owner)->resolve($node->id, $comment->id))->toThrow(ProfileRestrictionException::class)
        ->and(fn () => Documents::comments($owner)->delete($node->id, $comment->id))->toThrow(ProfileRestrictionException::class);
});
