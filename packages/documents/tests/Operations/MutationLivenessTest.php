<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Vaulter\Documents\Actions\Comments\AddCommentAction;
use Vaulter\Documents\Actions\Comments\DeleteCommentAction;
use Vaulter\Documents\Actions\Comments\ResolveCommentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Comments\DeleteCommentInput;
use Vaulter\Documents\Data\Comments\ResolveCommentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/**
 * A writer that holds the tree lock and the node lock re-checks that
 * the node is live and the drive writable. A trash or an archive that committed
 * between the caller's check and the lock leaves no state, history or comment behind.
 */
afterEach(fn () => Relation::morphMap([], false));

/** @return array{0: DriveHandle, 1: Node, 2: NodeSnapshot, 3: NodeComment} */
function livenessFixture(): array
{
    $handle = DocumentTestHelpers::drive();
    $snapshot = Documents::in($handle)->create('Spec', content: DocumentTestHelpers::content('first')->toArray());
    $node = DriveTestHelpers::model($snapshot);
    AccessTestHelpers::grant($snapshot, RoleRegistry::OWNER, subjectId: 7);
    $comment = Documents::comments($handle->actingAs(AccessTestHelpers::user(7)))->add($snapshot->id, new AddCommentInput('seed'));

    return [$handle, $node, $snapshot, NodeComment::query()->findOrFail($comment->id)];
}

/** What a refused writer must not have changed. */
function livenessState(Node $node): array
{
    $document = DocumentTestHelpers::document($node);

    return [$document->state_version, $document->collab_epoch, DocumentRevision::query()->where('document_id', $document->getKey())->count(),
        NodeComment::query()->where('node_id', $node->getKey())->count(), NodeComment::query()->where('node_id', $node->getKey())->whereNotNull('resolved_at')->count()];
}

/** The writers, each handed the node row the caller read BEFORE the trash. */
function livenessWriters(): array
{
    return [
        'update' => fn (Node $node, NodeComment $comment, $context) => app(UpdateDocumentContentAction::class)->execute($context,
            new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('late'))),
        'named revision' => fn (Node $node, NodeComment $comment, $context) => app(CreateNamedRevisionAction::class)->execute($context,
            new CreateNamedRevisionInput(node: $node, label: 'late')),
        'restore' => fn (Node $node, NodeComment $comment, $context) => app(RestoreDocumentRevisionAction::class)->execute($context,
            new RestoreDocumentRevisionInput(node: $node, revision: DocumentTestHelpers::head($node)->getKey())),
        'retention' => fn (Node $node, NodeComment $comment, $context) => app(UpdateRevisionRetentionAction::class)->execute($context,
            new UpdateRevisionRetentionInput(node: $node, revision: DocumentTestHelpers::head($node)->getKey(), keepForever: true)),
        'comment add' => fn (Node $node, NodeComment $comment, $context) => app(AddCommentAction::class)->execute($context, $node, new AddCommentInput('late')),
        'comment resolve' => fn (Node $node, NodeComment $comment, $context) => app(ResolveCommentAction::class)->execute($context, new ResolveCommentInput($comment)),
        'comment delete' => fn (Node $node, NodeComment $comment, $context) => app(DeleteCommentAction::class)->execute($context, new DeleteCommentInput($comment)),
    ];
}

it('refuses a writer whose node was trashed before its lock with node_not_found and no side effect', function (string $writer): void {
    [$handle, $node, $snapshot, $comment] = livenessFixture();
    $context = DocumentTestHelpers::context($handle, 7);
    $stale = Node::query()->findOrFail($node->getKey());
    $before = livenessState($node);

    $handle->nodes()->trash($snapshot);

    expect(fn () => livenessWriters()[$writer]($stale, $comment, $context))->toThrow(NodeNotFoundException::class)
        ->and(livenessState($node))->toBe($before);
})->with(array_keys(livenessWriters()));

it('refuses a user writer on an archived drive with drive_archived and no side effect', function (string $writer): void {
    [$handle, $node, , $comment] = livenessFixture();
    $context = DocumentTestHelpers::context($handle, 7);
    $stale = Node::query()->findOrFail($node->getKey());
    $before = livenessState($node);

    $handle->archive();

    expect(fn () => livenessWriters()[$writer]($stale, $comment, $context))->toThrow(DriveArchivedException::class)
        ->and(livenessState($node))->toBe($before);
})->with(array_keys(livenessWriters()));

it('a comment author cannot delete their comment in an archived drive', function (): void {
    [$handle, , $snapshot, $comment] = livenessFixture();
    $author = $handle->actingAs(AccessTestHelpers::user(7));

    $handle->archive();

    expect(fn () => Documents::comments($author)->delete($snapshot->id, $comment->getKey()))->toThrow(DriveArchivedException::class)
        ->and(NodeComment::query()->whereKey($comment->getKey())->exists())->toBeTrue();
});
