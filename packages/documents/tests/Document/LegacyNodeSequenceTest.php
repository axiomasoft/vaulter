<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Events\Revisions\DocumentRevisionCreated;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Exceptions\Nodes\SequenceOverflowException;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Fixtures\Documents\FakeExporter;

function legacySequenceDocument(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Sequence document',
        content: DocumentTestHelpers::content('initial'),
        parent: $drive->root(),
    ));
}

it('starts a document at zero and counts changed append and debounce writes once', function (): void {
    $node = legacySequenceDocument();
    expect($node->sequence)->toBe(0);

    $action = app(UpdateDocumentContentAction::class);
    $first = $action->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('first'),
        expectedSequence: 0,
    ));
    expect($node->refresh()->sequence)->toBe(1);

    $second = $action->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('second'),
        expectedSequence: 1,
    ));
    expect($second->id)->toBe($first->id)
        ->and($node->refresh()->sequence)->toBe(2)
        ->and(DocumentRevision::query()->where('node_id', $node->id)->count())->toBe(2);
});

it('checks stale expectation before an identical overwrite and leaves no side effects', function (): void {
    $node = legacySequenceDocument();
    $action = app(UpdateDocumentContentAction::class);
    $content = DocumentTestHelpers::content('saved');
    $head = $action->execute(new UpdateDocumentContentInput(node: $node, content: $content, expectedSequence: 0));
    Event::fake([DocumentContentUpdated::class]);

    $same = $action->execute(new UpdateDocumentContentInput(node: $node, content: $content, expectedSequence: 1));
    expect($same->id)->toBe($head->id)
        ->and($node->refresh()->sequence)->toBe(1);
    Event::assertNotDispatched(DocumentContentUpdated::class);

    expect(fn () => $action->execute(new UpdateDocumentContentInput(node: $node, content: $content, expectedSequence: 0)))
        ->toThrow(StaleNodeException::class);
    expect($node->refresh()->sequence)->toBe(1)
        ->and(DocumentRevision::query()->where('node_id', $node->id)->count())->toBe(2);
    Event::assertNotDispatched(DocumentContentUpdated::class);
});

it('counts a new head identity even when an appended autosave has identical content', function (): void {
    $node = legacySequenceDocument();
    $originalId = $node->current_version_id;
    $version = app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('initial'),
        expectedSequence: 0,
    ));

    expect($version->id)->not->toBe($originalId)
        ->and($node->refresh()->sequence)->toBe(1)
        ->and($node->current_version_id)->toBe($version->id);
});

it('reloads the protected head instead of trusting a stale caller model', function (): void {
    $node = legacySequenceDocument();
    $stale = $node->replicate();
    $stale->setRawAttributes($node->getAttributes(), sync: true);
    $stale->exists = true;
    $action = app(UpdateDocumentContentAction::class);
    $head = $action->execute(new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('new head')));

    $updated = $action->execute(new UpdateDocumentContentInput(
        node: $stale,
        content: DocumentTestHelpers::content('latest edit'),
        expectedSequence: 1,
    ));
    expect($updated->id)->toBe($head->id)
        ->and($node->refresh()->sequence)->toBe(2)
        ->and($updated->tipTapContent()->toArray())->toBe(DocumentTestHelpers::content('latest edit')->toArray());
});

it('counts named and restored heads even when their content is unchanged', function (): void {
    $node = legacySequenceDocument();
    $originalId = $node->current_version_id;
    $named = app(CreateNamedRevisionAction::class)->execute(new CreateNamedRevisionInput(
        node: $node,
        label: 'checkpoint',
        expectedSequence: 0,
    ));
    expect($node->refresh()->sequence)->toBe(1);

    $restored = app(RestoreDocumentRevisionAction::class)->execute(node: $node, versionId: $originalId, expectedSequence: 1);
    expect($restored->id)->not->toBe($named->id)
        ->and($node->refresh()->sequence)->toBe(2)
        ->and($node->current_version_id)->toBe($restored->id);

    Event::fake([DocumentRevisionCreated::class]);
    expect(fn () => app(CreateNamedRevisionAction::class)->execute(new CreateNamedRevisionInput(node: $node, label: 'stale', expectedSequence: 1)))
        ->toThrow(StaleNodeException::class);
    expect(fn () => app(RestoreDocumentRevisionAction::class)->execute(node: $node, versionId: $originalId, expectedSequence: 1))
        ->toThrow(StaleNodeException::class);
    expect(DocumentRevision::query()->where('node_id', $node->id)->count())->toBe(3);
    Event::assertNotDispatched(DocumentRevisionCreated::class);
});

it('counts Yjs append overwrite and projection changes while identical state is a noop', function (): void {
    $node = legacySequenceDocument();
    $action = app(PersistCollaborationStateAction::class);
    $first = $action->execute(new PersistCollaborationStateInput(node: $node, yjsState: "first\0state"));
    expect($node->refresh()->sequence)->toBe(1);

    $second = $action->execute(new PersistCollaborationStateInput(node: $node, yjsState: "second\0state"));
    expect($second->id)->toBe($first->id)
        ->and($node->refresh()->sequence)->toBe(2);

    Event::fake([DocumentContentUpdated::class]);
    $same = $action->execute(new PersistCollaborationStateInput(node: $node, yjsState: "second\0state"));
    expect($same->id)->toBe($second->id)
        ->and($node->refresh()->sequence)->toBe(2);
    Event::assertNotDispatched(DocumentContentUpdated::class);

    $action->execute(new PersistCollaborationStateInput(
        node: $node,
        yjsState: "second\0state",
        content: DocumentTestHelpers::content('projection changed'),
    ));
    expect($node->refresh()->sequence)->toBe(3);
    Event::assertDispatchedTimes(DocumentContentUpdated::class, 1);
});

it('rolls back content and head writes when the sequence is exhausted', function (): void {
    $node = legacySequenceDocument();
    Node::query()->whereKey($node->id)->update(['sequence' => PHP_INT_MAX]);
    $headId = $node->current_version_id;
    Event::fake([DocumentContentUpdated::class]);

    expect(fn () => app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('uncommitted'),
        expectedSequence: PHP_INT_MAX,
    )))->toThrow(SequenceOverflowException::class);

    expect($node->refresh()->sequence)->toBe(PHP_INT_MAX)
        ->and($node->current_version_id)->toBe($headId)
        ->and(DocumentRevision::query()->where('node_id', $node->id)->count())->toBe(1);
    Event::assertNotDispatched(DocumentContentUpdated::class);
});

it('keeps history-only retention changes outside the editorial sequence', function (): void {
    $node = legacySequenceDocument();
    $named = app(CreateNamedRevisionAction::class)->execute(new CreateNamedRevisionInput(
        node: $node,
        label: 'retained',
        keepForever: true,
        expectedSequence: 0,
    ));
    app(UpdateRevisionRetentionAction::class)->execute(node: $node, versionId: $named->id);

    expect($named->refresh()->keep_forever)->toBeFalse()
        ->and($node->refresh()->sequence)->toBe(1);
});

it('owns runtime document types and exporter registrations in the documents manager', function (): void {
    Documents::types()->register('sheet', ['label' => 'Sheet']);
    expect(Documents::types()->all())->toHaveKey('sheet', ['label' => 'Sheet']);

    Documents::exporters()->register(FakeExporter::class);
    expect(Documents::exporters()->get('fake'))->toBeInstanceOf(FakeExporter::class)
        ->and(Documents::exporters()->formats())->toContain('fake', 'html', 'markdown');

    $exporter = new FakeExporter;
    Documents::exporters()->register($exporter);
    expect(Documents::exporters()->get('fake'))->toBe($exporter);
});
