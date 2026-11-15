<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Data\Comments\AddCommentInput;
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Idempotency\CommentDeleteReceipt;
use Vaulter\Documents\Idempotency\CommentReceiptCodec;
use Vaulter\Documents\Idempotency\DocumentRevisionSnapshotCodec;
use Vaulter\Documents\Idempotency\DocumentSnapshotCodec;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Exceptions\Idempotency\IdempotencyKeyReusedException;
use Vaulter\Idempotency\IdempotencyStore;
use Vaulter\Idempotency\ReplayedProblemException;

/**
 * The writes of documents and comments are receipt operations. A retry under the same
 * Idempotency-Key and request returns what the first request answered and changes nothing; a comment has a version of its
 * own, a resolution raises it, and a resolve or a delete on a version that is gone is refused (412 stale_write).
 */
afterEach(fn () => Relation::morphMap([], false));

/** @return array{DriveHandle, DriveHandle} the drive acting as the document's writer, and the system handle */
function idempotentDocuments(): array
{
    $system = DocumentTestHelpers::drive();

    return [$system, $system];
}

/** @return array{0: DriveHandle, 1: string, 2: Closure(string, int): DriveHandle} a folder with an owner (user 1), a commenter (user 2) and its keyed handles */
function idempotentThread(): array
{
    $system = DocumentTestHelpers::drive();
    $node = $system->folders()->create('thread');
    AccessTestHelpers::grant($node, RoleRegistry::OWNER, subjectId: 1);
    AccessTestHelpers::grant($node, RoleRegistry::COMMENTER, subjectId: 2);

    return [$system, $node->id, static fn (string $key, int $user = 1): DriveHandle => $system->actingAs(AccessTestHelpers::user($user))->withIdempotencyKey($key)];
}

it('replays a document creation once', function (): void {
    [$system] = idempotentDocuments();
    $keyed = $system->withIdempotencyKey('create-1');

    $first = Documents::in($keyed)->create('Spec', content: DocumentTestHelpers::content('one')->toArray());
    $again = Documents::in($keyed)->create('Spec', content: DocumentTestHelpers::content('one')->toArray());

    expect($again)->toEqual($first)
        ->and(Node::query()->where('name', 'Spec')->count())->toBe(1);
});

it('gives a created document and its replay the same reader key, which the semantic receipt does not store', function (): void {
    [$system] = idempotentDocuments();
    $keyed = $system->withIdempotencyKey('create-key-1');

    $first = Documents::in($keyed)->create('Keyed', content: DocumentTestHelpers::content('one')->toArray());
    $again = Documents::in($keyed)->create('Keyed', content: DocumentTestHelpers::content('one')->toArray());
    $stored = json_encode(DB::table('v_idempotency_keys')->get()->all(), JSON_THROW_ON_ERROR);

    expect($first->syncKey)->toBeString()->toHaveLength(43)
        ->and($again->syncKey)->toBe($first->syncKey)
        ->and($stored)->not->toContain((string) $first->syncKey);
});

it('enriches a replay after a key generation rotation with the fresh key while the receipt stays the same', function (): void {
    [$system] = idempotentDocuments();
    $keyed = $system->withIdempotencyKey('create-rotate-1');

    $first = Documents::in($keyed)->create('Rotated', content: DocumentTestHelpers::content('one')->toArray());
    config()->set('vaulter-drive.changes.key_generation', 2);
    $again = Documents::in($keyed)->create('Rotated', content: DocumentTestHelpers::content('one')->toArray());

    expect($again->syncKey)->toBeString()->toHaveLength(43)->not->toBe($first->syncKey)
        ->and($again->withSyncKey(null))->toEqual($first->withSyncKey(null))
        ->and(Node::query()->where('name', 'Rotated')->count())->toBe(1);
});

it('refuses the replay of a document creation, and gives it no key, to an actor who lost access', function (): void {
    [$system, $node, $keyed] = idempotentThread();
    $content = DocumentTestHelpers::content('one')->toArray();
    $first = Documents::in($keyed('create-revoked', 1))->create('Revoked', content: $content, parent: $node);
    app(VaulterDatabase::class)->write(static fn () => NodeGrant::query()->where('subject_id', 1)->delete());

    expect($first->syncKey)->toBeString()
        ->and(fn () => Documents::in($keyed('create-revoked', 1))->create('Revoked', content: $content, parent: $node))->toThrow(NodeNotFoundException::class)
        ->and(Node::query()->where('name', 'Revoked')->count())->toBe(1);
});

it('refuses the same key for another document and creates nothing', function (): void {
    [$system] = idempotentDocuments();
    $keyed = $system->withIdempotencyKey('create-2');
    Documents::in($keyed)->create('Spec', content: DocumentTestHelpers::content('one')->toArray());

    expect(fn () => Documents::in($keyed)->create('Other', content: DocumentTestHelpers::content('one')->toArray()))->toThrow(IdempotencyKeyReusedException::class)
        ->and(Node::query()->where('name', 'Other')->exists())->toBeFalse();
});

it('replays a content update, a checkpoint and a restore without a second write', function (): void {
    [$system] = idempotentDocuments();
    $node = Documents::in($system)->create('Spec', content: DocumentTestHelpers::content('first')->toArray());
    $state = Documents::in($system)->content($node->id)->stateVersion;

    $update = fn () => Documents::in($system->withIdempotencyKey('update-1'))->update($node->id, DocumentTestHelpers::content('second')->toArray(), $state);
    $saved = $update();
    $again = $update();
    expect($again)->toEqual($saved)
        ->and($again->content->toArray())->toBe(DocumentTestHelpers::content('second')->toArray())
        ->and($again->stateVersion)->toBe($state + 1)
        ->and(Document::query()->where('node_id', $node->id)->firstOrFail()->state_version)->toBe($state + 1);

    $snapshot = fn () => Documents::in($system->withIdempotencyKey('snapshot-1'))->snapshot($node->id, 'named');
    $revisions = DocumentRevision::query()->count();
    $checkpoint = $snapshot();
    expect($snapshot())->toEqual($checkpoint)
        ->and(DocumentRevision::query()->count())->toBe($revisions + 1);

    $current = Documents::in($system)->content($node->id)->stateVersion;
    $restore = fn () => Documents::in($system->withIdempotencyKey('restore-1'))->restore($node->id, $checkpoint->id, $current);
    $restored = $restore();
    expect($restore())->toEqual($restored)
        ->and(Document::query()->where('node_id', $node->id)->firstOrFail()->state_version)->toBe($restored->stateVersion);
});

it('does not return a purged document from an old receipt through permissions on the root', function (string $operation, bool $keepEdit): void {
    [$system] = idempotentDocuments();
    $root = Node::query()->findOrFail($system->root()->id);
    AccessTestHelpers::grant($root, RoleRegistry::OWNER, subjectId: 1);
    $actor = $system->actingAs(AccessTestHelpers::user(1));
    $node = Documents::in($system)->create('Private', content: DocumentTestHelpers::content('private content')->toArray());
    $revision = Documents::in($system)->snapshot($node->id, 'before');
    $state = Documents::in($actor)->content($node->id)->stateVersion;
    $keyed = Documents::in($actor->withIdempotencyKey('gone-'.$operation));
    $retry = match ($operation) {
        'update' => fn () => $keyed->update($node->id, DocumentTestHelpers::content('changed')->toArray(), $state),
        'snapshot' => fn () => $keyed->snapshot($node->id, 'saved'),
        'restore' => fn () => $keyed->restore($node->id, $revision->id, $state),
    };
    $retry();
    $receiptCount = DB::table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->count();
    $system->nodes()->trash($node->id);
    $system->trash()->purge($node->id);
    if (!$keepEdit) {
        app(VaulterDatabase::class)->write(static fn () => NodeGrant::query()->where('subject_id', 1)->delete());
        AccessTestHelpers::grant($root, RoleRegistry::PREVIEWER, subjectId: 1);
    }

    expect($retry)->toThrow(NodeNotFoundException::class)
        ->and(DB::table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->count())->toBe($receiptCount)
        ->and(Document::query()->where('node_id', $node->id)->exists())->toBeFalse();
})->with(['update', 'snapshot', 'restore'])->with([true, false]);

it('stores the stale state of a keyed update as a refusal and answers a retry the same way', function (): void {
    [$system] = idempotentDocuments();
    $node = Documents::in($system)->create('Spec', content: DocumentTestHelpers::content('first')->toArray());
    $state = Documents::in($system)->content($node->id)->stateVersion;
    Documents::in($system)->update($node->id, DocumentTestHelpers::content('landed')->toArray(), $state);

    $stale = fn () => Documents::in($system->withIdempotencyKey('stale-1'))->update($node->id, DocumentTestHelpers::content('late')->toArray(), $state);
    expect($stale)->toThrow(StaleDocumentStateException::class);
    try {
        $stale();
        $this->fail('The stored refusal was not replayed.');
    } catch (ReplayedProblemException $replayed) {
        expect($replayed->problemCode())->toBe('document_state_mismatch')->and($replayed->httpStatus())->toBe(412);
    }
});

it('replays a comment add, a resolution and a deletion once each', function (): void {
    [, $node, $keyed] = idempotentThread();

    $added = Documents::comments($keyed('add-1'))->add($node, new AddCommentInput('first'));
    expect(Documents::comments($keyed('add-1'))->add($node, new AddCommentInput('first')))->toEqual($added)
        ->and(NodeComment::query()->count())->toBe(1)
        ->and($added->version)->toBe(0);

    $resolved = Documents::comments($keyed('resolve-1'))->resolve($node, $added->id, $added->version);
    expect($resolved->version)->toBe(1)
        ->and(Documents::comments($keyed('resolve-1'))->resolve($node, $added->id, $added->version))->toEqual($resolved)
        ->and(NodeComment::query()->findOrFail($added->id)->version)->toBe(1);

    Documents::comments($keyed('delete-1'))->delete($node, $added->id, $resolved->version);
    Documents::comments($keyed('delete-1'))->delete($node, $added->id, $resolved->version);
    expect(NodeComment::query()->count())->toBe(0);
    // Without the key the comment is simply gone.
    expect(fn () => Documents::comments($keyed('other'))->delete($node, $added->id, $resolved->version))->toThrow(CommentNotFoundException::class);
});

it('refuses a resolve or a delete on a version that is gone, whichever write came first', function (): void {
    [, $node, $keyed] = idempotentThread();
    $comment = Documents::comments($keyed('a'))->add($node, new AddCommentInput('thread'));

    Documents::comments($keyed('b'))->resolve($node, $comment->id, 0);
    // A second resolution and a deletion that both read version 0 lose.
    expect(fn () => Documents::comments($keyed('c'))->resolve($node, $comment->id, 0))->toThrow(StaleWriteException::class)
        ->and(fn () => Documents::comments($keyed('d'))->delete($node, $comment->id, 0))->toThrow(StaleWriteException::class)
        ->and(NodeComment::query()->findOrFail($comment->id)->version)->toBe(1);

    Documents::comments($keyed('e'))->delete($node, $comment->id, 1);
    expect(fn () => Documents::comments($keyed('f'))->resolve($node, $comment->id, 1))->toThrow(CommentNotFoundException::class);
});

it('does not ask for a version when the caller gave none', function (): void {
    [, $node, $keyed] = idempotentThread();
    $comment = Documents::comments($keyed('a'))->add($node, new AddCommentInput('thread'));

    expect(Documents::comments($keyed('b'))->resolve($node, $comment->id)->version)->toBe(1)
        ->and(Documents::comments($keyed('c'))->resolve($node, $comment->id)->version)->toBe(2);
});

it('answers a replay of a deletion with the current authorization, by the recorded basis', function (): void {
    [, $node, $keyed] = idempotentThread();
    $comment = Documents::comments($keyed('a', 2))->add($node, new AddCommentInput('mine'));

    // User 2 deletes its own comment (authorship): the replay needs only that it still sees the node.
    Documents::comments($keyed('del', 2))->delete($node, $comment->id, 0);
    Documents::comments($keyed('del', 2))->delete($node, $comment->id, 0);

    // The owner deletes another's comment (Manage); once Manage is gone, the same key is refused.
    $other = Documents::comments($keyed('b', 2))->add($node, new AddCommentInput('theirs'));
    Documents::comments($keyed('del-manage', 1))->delete($node, $other->id, 0);
    app(VaulterDatabase::class)->write(static fn () => NodeGrant::query()->where('subject_id', 1)->delete());

    expect(fn () => Documents::comments($keyed('del-manage', 1))->delete($node, $other->id, 0))->toThrow(NodeNotFoundException::class);
});

it('refuses the replay of a resolution to an actor who lost Comment', function (): void {
    [$system, $node, $keyed] = idempotentThread();
    $comment = Documents::comments($keyed('a'))->add($node, new AddCommentInput('thread'));
    Documents::comments($keyed('resolve'))->resolve($node, $comment->id, 0);
    NodeGrant::query()->where('subject_id', 1)->update(['role_key' => RoleRegistry::READER]);

    expect(fn () => Documents::comments($keyed('resolve'))->resolve($node, $comment->id, 0))->toThrow(PermissionDeniedException::class);
});

it('stores a refused resolve or delete of an author on its authorship, so the retry replays the 412 to a commenter', function (string $verb): void {
    [$system, $node, $keyed] = idempotentThread();
    // User 2 is a commenter: it may resolve and delete its own comment, never another's (no Edit, no Manage).
    $comment = Documents::comments($keyed('a', 2))->add($node, new AddCommentInput('mine'));
    Documents::comments($system->actingAs(AccessTestHelpers::user(1)))->resolve($node, $comment->id, 0);

    $write = fn () => $verb === 'resolve'
        ? Documents::comments($keyed('stale-'.$verb, 2))->resolve($node, $comment->id, 0)
        : Documents::comments($keyed('stale-'.$verb, 2))->delete($node, $comment->id, 0);
    expect($write)->toThrow(StaleWriteException::class);

    $scope = app(VaulterDatabase::class)->connection()->table(app(IdempotencyStore::class)->table())->where('operation', 'documents.comments.'.$verb)->value('auth_scope_kind');
    expect($scope)->toBe('node-author');
    try {
        $write();
        $this->fail('The stored refusal was not replayed.');
    } catch (ReplayedProblemException $replayed) {
        expect($replayed->problemCode())->toBe('stale_write')->and($replayed->httpStatus())->toBe(412);
    }
    expect(NodeComment::query()->findOrFail($comment->id)->version)->toBe(1);
})->with(['resolve', 'delete']);

it('round-trips the receipts and decodes only the version it knows', function (): void {
    [$system] = idempotentDocuments();
    $node = Documents::in($system)->create('Spec', content: DocumentTestHelpers::content('first')->toArray());
    $document = Documents::in($system)->content($node->id);
    $checkpoint = Documents::in($system)->snapshot($node->id, 'v1');

    $documents = new DocumentSnapshotCodec(app(ContentDecoder::class));
    $revisions = new DocumentRevisionSnapshotCodec;
    $comments = new CommentReceiptCodec;
    $comment = new CommentSnapshot(id: '7', nodeId: $node->id, parentId: null, authorType: 'user', authorId: '1', body: 'b', anchor: ['from' => 1], resolvedAt: null, resolvedBy: null, createdAt: new DateTimeImmutable('2026-10-18T10:00:00.000000+00:00'), version: 3);
    $deleted = new CommentDeleteReceipt(id: '7', nodeId: $node->id, driveId: '1', byAuthor: true);

    expect($documents->decode($documents->encode($document), 1))->toEqual($document)
        ->and($documents->resource($document)->id)->toBe($node->id)
        ->and($revisions->decode($revisions->encode($checkpoint), 1))->toEqual($checkpoint)
        ->and($comments->decode($comments->encode($comment), 1))->toEqual($comment)
        ->and($comments->decode($comments->encode($deleted), 1))->toEqual($deleted)
        ->and(fn () => $documents->decode($documents->encode($document), 2))->toThrow(LogicException::class)
        ->and(fn () => $revisions->decode($revisions->encode($checkpoint), 2))->toThrow(LogicException::class)
        ->and(fn () => $comments->decode($comments->encode($comment), 2))->toThrow(LogicException::class)
        ->and(fn () => $documents->encode($comment))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $comments->encode($document))->toThrow(InvalidArgumentException::class);
    expect($document)->toBeInstanceOf(DocumentSnapshot::class);
});

it('keeps the content hash, the state version and the node sequence apart', function (): void {
    [$system] = idempotentDocuments();
    $node = Documents::in($system)->create('Spec', content: DocumentTestHelpers::content('same')->toArray());
    $before = Documents::in($system)->content($node->id);
    $sequence = Node::query()->findOrFail($node->id)->sequence;

    // A metadata change moves the node sequence and leaves the content hash and the state version.
    $system->nodes()->rename($node->id, 'Renamed spec');
    $renamed = Documents::in($system)->content($node->id);
    expect(Node::query()->findOrFail($node->id)->sequence)->toBe($sequence + 1)
        ->and($renamed->contentHash)->toBe($before->contentHash)
        ->and($renamed->stateVersion)->toBe($before->stateVersion);

    // A save of the same bytes is a new state of the same hash: the version moves, the hash does not.
    $saved = Documents::in($system->withIdempotencyKey('same-bytes'))->update($node->id, DocumentTestHelpers::content('same')->toArray(), $before->stateVersion);
    expect($saved->contentHash)->toBe($before->contentHash)
        ->and($saved->stateVersion)->toBe($before->stateVersion + 1);
});

it('treats an omitted expected state version and an explicit null as one request, and a given version as another', function (): void {
    [$system] = idempotentDocuments();
    $node = Documents::in($system)->create('Spec', content: DocumentTestHelpers::content('first')->toArray());
    $state = Documents::in($system)->content($node->id)->stateVersion;
    $keyed = Documents::in($system->withIdempotencyKey('null-1'));

    $saved = $keyed->update($node->id, DocumentTestHelpers::content('second')->toArray());
    expect($keyed->update($node->id, DocumentTestHelpers::content('second')->toArray(), null))->toEqual($saved)
        ->and(fn () => $keyed->update($node->id, DocumentTestHelpers::content('second')->toArray(), $state))->toThrow(IdempotencyKeyReusedException::class)
        ->and(Document::query()->where('node_id', $node->id)->firstOrFail()->state_version)->toBe($state + 1);
});

it('keeps the raw receipt of a document update semantic: state version and hash, no tag or sync key', function (): void {
    [$system] = idempotentDocuments();
    $node = Documents::in($system)->create('Spec', content: DocumentTestHelpers::content('first')->toArray());
    $saved = Documents::in($system->withIdempotencyKey('raw-doc-1'))->update($node->id, DocumentTestHelpers::content('second')->toArray());

    $row = app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table(IdempotencyStore::ENTITY))->where('idempotency_key', base64_encode('raw-doc-1'))->first();
    $raw = (string) $row?->receipt_body;

    expect($row?->receipt_headers)->toBeNull()
        ->and($raw)->toContain((string) $saved->contentHash)
        ->and($raw)->not->toContain('sync_key')
        ->and($raw)->not->toContain('etag')
        ->and($raw)->not->toContain('abilities');
});
