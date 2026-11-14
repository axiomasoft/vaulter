<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Export\ExportDocumentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Events\Documents\DocumentCreated;
use Vaulter\Documents\Events\Revisions\DocumentRestored;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Documents\Tests\Fixtures\StateWrites;
use Vaulter\Documents\Tests\Types\TypesTestCase;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Documents\Types\SpreadsheetDocumentType;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;

/*
 * The writer matrix of the validators: every operation that changes a document node or
 * its content, with the change it makes to the node validator (v_nodes.sequence) and to
 * the content validators (v_documents.state_version, v_documents.collab_epoch). A node
 * edit and a content edit never move each other's validator.
 */
beforeEach(function (): void {
    // Every effective write also takes a checkpoint, so each row has a revision to point at.
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

/** @return array{drive: DriveHandle, node: Node} */
function matrixSubject(bool $collaborative = true): array
{
    if (!$collaborative) {
        TypesTestCase::installTypes([RichTextDocumentType::class, SpreadsheetDocumentType::class]);
    }
    $drive = DocumentTestHelpers::drive();
    $node = app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Matrix document',
        content: DocumentTestHelpers::content('initial'),
        parent: DriveTestHelpers::model($drive->root()),
        type: $collaborative ? RichTextDocumentType::KEY : SpreadsheetDocumentType::KEY,
    ));

    return ['drive' => $drive, 'node' => $node];
}

/** @return array{int, int, int} node sequence, state version, collaboration epoch */
function matrixValidators(Node $node): array
{
    $document = Document::query()->where('node_id', $node->getKey())->firstOrFail();

    return [Node::query()->whereKey($node->getKey())->firstOrFail()->sequence, $document->state_version, $document->collab_epoch];
}

function matrixYjs(Node $node, string $state = 'yjs-state', bool $seed = false): void
{
    StateWrites::yjs($node, $state, GrantedDocuments::tiptap($state), $seed);
}

/**
 * Prepare and act per writer; both receive the document node and its drive handle.
 *
 * @return array<string, array{prepare: ?Closure, act: Closure}>
 */
function matrixWriters(): array
{
    $ctx = static fn (Node $node): DriveContext => DocumentTestHelpers::context($node);
    $update = static fn (Node $node, string $text): mixed => app(UpdateDocumentContentAction::class)->execute(
        $ctx($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content($text)),
    );

    return [
        'rename' => ['prepare' => null, 'act' => static fn (Node $node, DriveHandle $drive) => $drive->nodes()->rename($node->getKey(), 'Renamed', expectedSequence: 0)],
        'move' => ['prepare' => null, 'act' => static function (Node $node, DriveHandle $drive): void {
            $folder = $drive->folders()->create('Target');
            $drive->nodes()->move($node->getKey(), $folder->id);
        }],
        'trash' => ['prepare' => null, 'act' => static fn (Node $node, DriveHandle $drive) => $drive->nodes()->trash($node->getKey(), expectedSequence: 0)],
        'trash restore' => [
            'prepare' => static fn (Node $node, DriveHandle $drive) => $drive->nodes()->trash($node->getKey()),
            'act' => static fn (Node $node, DriveHandle $drive) => $drive->trash()->restore($node->getKey()),
        ],
        'setProperties' => ['prepare' => null, 'act' => static fn (Node $node, DriveHandle $drive) => $drive->nodes()->setProperties($node->getKey(), 'acme', ['k' => 'v'], expectedSequence: 0)],
        // Stars are personal: a user actor, never the system.
        'star' => ['prepare' => null, 'act' => static fn (Node $node, DriveHandle $drive) => $drive->actingAs(DriveTestHelpers::actor($drive, 9))->nodes()->star($node->getKey())],
        'unstar' => [
            'prepare' => static fn (Node $node, DriveHandle $drive) => $drive->actingAs(DriveTestHelpers::actor($drive, 9))->nodes()->star($node->getKey()),
            'act' => static fn (Node $node, DriveHandle $drive) => $drive->actingAs(DriveTestHelpers::actor($drive, 9))->nodes()->unstar($node->getKey()),
        ],
        'restrictInheritance' => ['prepare' => null, 'act' => static fn (Node $node, DriveHandle $drive) => $drive->access()->restrictInheritance($node->getKey())],
        'copy' => ['prepare' => null, 'act' => static fn (Node $node, DriveHandle $drive) => $drive->nodes()->copy($node->getKey())],
        'update' => ['prepare' => null, 'act' => static fn (Node $node) => $update($node, 'edited')],
        'update (identical content)' => [
            'prepare' => static fn (Node $node) => $update($node, 'edited'),
            'act' => static fn (Node $node) => $update($node, 'edited'),
        ],
        'update (document with Yjs state)' => [
            'prepare' => static fn (Node $node) => matrixYjs($node, 'room', seed: true),
            'act' => static fn (Node $node) => $update($node, 'json replace'),
        ],
        'collab seed' => ['prepare' => null, 'act' => static fn (Node $node) => matrixYjs($node, 'seeded', seed: true)],
        'collab store' => [
            'prepare' => static fn (Node $node) => matrixYjs($node, 'room', seed: true),
            'act' => static fn (Node $node) => matrixYjs($node, 'room edit'),
        ],
        'collab store (identical state)' => [
            'prepare' => static fn (Node $node) => matrixYjs($node, 'room', seed: true),
            'act' => static fn (Node $node) => matrixYjs($node, 'room'),
        ],
        'restore' => ['prepare' => null, 'act' => static fn (Node $node) => app(RestoreDocumentRevisionAction::class)->execute(
            $ctx($node), new RestoreDocumentRevisionInput(node: $node, revision: DocumentTestHelpers::head($node)),
        )],
        'snapshot' => ['prepare' => null, 'act' => static fn (Node $node) => app(CreateNamedRevisionAction::class)->execute(
            $ctx($node), new CreateNamedRevisionInput(node: $node, label: 'named', keepForever: false),
        )],
        'setRetention' => ['prepare' => null, 'act' => static fn (Node $node) => app(UpdateRevisionRetentionAction::class)->execute(
            $ctx($node), new UpdateRevisionRetentionInput(node: $node, revision: DocumentTestHelpers::head($node), keepForever: true),
        )],
        'export' => ['prepare' => null, 'act' => static fn (Node $node) => app(ExportDocumentAction::class)->execute(
            $ctx($node), new ExportDocumentInput($node, 'html'),
        )],
    ];
}

dataset('document writers', [
    // writer => [sequence delta, state_version delta, collab_epoch delta]
    'rename' => ['rename', [1, 0, 0]],
    'move' => ['move', [1, 0, 0]],
    'trash' => ['trash', [1, 0, 0]],
    'trash restore' => ['trash restore', [1, 0, 0]],
    'setProperties' => ['setProperties', [1, 0, 0]],
    'star' => ['star', [0, 0, 0]],
    'unstar' => ['unstar', [0, 0, 0]],
    'restrictInheritance (ACL, not the node)' => ['restrictInheritance', [0, 0, 0]],
    'copy (the source is untouched)' => ['copy', [0, 0, 0]],
    'update' => ['update', [0, 1, 1]],
    'update, identical content (no write)' => ['update (identical content)', [0, 0, 0]],
    'update of a document with Yjs state (fences open rooms)' => ['update (document with Yjs state)', [0, 1, 1]],
    'collab seed' => ['collab seed', [0, 1, 0]],
    'collab store' => ['collab store', [0, 1, 0]],
    'collab store, identical state (no write)' => ['collab store (identical state)', [0, 0, 0]],
    'restore' => ['restore', [0, 1, 1]],
    'snapshot' => ['snapshot', [0, 0, 0]],
    'setRetention' => ['setRetention', [0, 0, 0]],
    'export' => ['export', [0, 0, 0]],
]);

it('moves exactly the documented validators: :dataset', function (string $writer, array $delta): void {
    // Only an unbound type has the no-op contract for an identical JSON save.
    ['drive' => $drive, 'node' => $node] = matrixSubject($writer !== 'update (identical content)');
    $row = matrixWriters()[$writer];
    if ($row['prepare'] !== null) {
        ($row['prepare'])($node, $drive);
    }
    $before = matrixValidators($node);

    ($row['act'])($node, $drive);

    $after = matrixValidators($node);
    expect([$after[0] - $before[0], $after[1] - $before[1], $after[2] - $before[2]])->toBe($delta);
})->with('document writers');

it('starts a new document at sequence 0, state version 1 and epoch 0', function (): void {
    ['node' => $node] = matrixSubject();

    expect(matrixValidators($node))->toBe([0, 1, 0]);
});

it('gives a copy its own validators: sequence 0, state version 1, epoch 0, whatever the source has', function (): void {
    ['drive' => $drive, 'node' => $node] = matrixSubject();
    matrixYjs($node, 'room', seed: true);
    $drive->nodes()->rename($node->getKey(), 'Renamed');

    $copy = $drive->nodes()->copy($node->getKey());

    expect(matrixValidators(Node::query()->findOrFail($copy->id)))->toBe([0, 1, 0])
        ->and(matrixValidators($node))->toBe([1, 2, 0]);
});

it('purges a document without moving the validators of its siblings', function (): void {
    ['drive' => $drive, 'node' => $node] = matrixSubject();
    $sibling = $drive->nodes()->copy($node->getKey());
    $siblingModel = Node::query()->findOrFail($sibling->id);
    $drive->nodes()->trash($node->getKey());
    $before = matrixValidators($siblingModel);

    $drive->trash()->purge($node->getKey());

    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse()
        ->and(Document::query()->where('node_id', $node->getKey())->exists())->toBeFalse()
        ->and(matrixValidators($siblingModel))->toBe($before);
});

it('keeps the node validator independent from content edits in either order', function (): void {
    ['drive' => $drive, 'node' => $node] = matrixSubject();

    $drive->nodes()->rename($node->getKey(), 'First', expectedSequence: 0);
    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(
        node: $node, content: DocumentTestHelpers::content('after rename'), expectedStateVersion: 1,
    ));
    $drive->nodes()->rename($node->getKey(), 'Second', expectedSequence: 1);

    expect(matrixValidators($node))->toBe([2, 2, 1]);
});

/** A plain file node: only the node validator exists, and only a new head moves it. */
function matrixFile(): array
{
    $drive = DocumentTestHelpers::drive();
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('one'), 'file.txt'));

    return ['drive' => $drive, 'file' => $file];
}

it('moves a file node validator only when the head revision changes: :dataset', function (string $writer, int $delta): void {
    ['drive' => $drive, 'file' => $file] = matrixFile();
    $drive->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor('two'), 'file.txt'));
    $revisions = $drive->files()->revisions($file->id)->items;
    $older = $revisions[array_key_last($revisions)];
    $before = Node::query()->findOrFail($file->id)->sequence;

    match ($writer) {
        'upload revision' => $drive->files()->addRevision($file->id, UploadSource::fromStream(DriveTestHelpers::streamFor('three'), 'file.txt')),
        'restore revision' => $drive->files()->restoreRevision($file->id, $older->id),
        'delete revision' => $drive->files()->deleteRevision($file->id, $older->id),
        'revision retention' => $drive->files()->setRevisionRetention($file->id, $older->id, true),
    };

    expect(Node::query()->findOrFail($file->id)->sequence - $before)->toBe($delta);
})->with([
    'upload revision' => ['upload revision', 1],
    'restore revision' => ['restore revision', 1],
    'delete revision (history, not the head)' => ['delete revision', 0],
    'revision retention (history, not the head)' => ['revision retention', 0],
]);

it('carries the content validators the writer produced in every document event', function (): void {
    Event::fake([DocumentCreated::class, DocumentContentUpdated::class, DocumentRevisionCreated::class, DocumentRestored::class]);
    ['drive' => $drive, 'node' => $node] = matrixSubject();
    Event::assertDispatched(DocumentCreated::class, static fn (DocumentCreated $e): bool => [$e->stateVersion, $e->epoch] === [1, 0]);

    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('edited')));
    Event::assertDispatched(DocumentContentUpdated::class, static fn (DocumentContentUpdated $e): bool => [$e->stateVersion, $e->epoch] === [2, 1]);

    // A binary state written by the collaboration store moves the validator like any content write (the event is the store's: see the collaboration suite).
    matrixYjs($node, 'room edit');

    app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node), new CreateNamedRevisionInput(node: $node, label: 'named', keepForever: false));
    Event::assertDispatched(DocumentRevisionCreated::class, static fn (DocumentRevisionCreated $e): bool => !$e->restored && [$e->stateVersion, $e->epoch] === [3, 1]);

    app(RestoreDocumentRevisionAction::class)->execute(DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(node: $node, revision: DocumentTestHelpers::revisions($node)->first()));
    Event::assertDispatched(DocumentRevisionCreated::class, static fn (DocumentRevisionCreated $e): bool => $e->restored && [$e->stateVersion, $e->epoch] === [4, 2]);
    Event::assertDispatched(DocumentRestored::class, static fn (DocumentRestored $e): bool => [$e->stateVersion, $e->epoch] === [4, 2]);
});
