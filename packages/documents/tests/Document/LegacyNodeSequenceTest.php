<?php

declare(strict_types=1);

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
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Facades\Vaulter;
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

it('leaves node.sequence alone on every document content write and counts them in state_version (D35)', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    $node = legacySequenceDocument();
    $first = DocumentTestHelpers::head($node);
    expect($node->refresh()->sequence)->toBe(0)
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(1);

    app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('first'),
        expectedStateVersion: 1,
    ));
    app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(node: $node, yjsState: "yjs\0state"));
    app(CreateNamedRevisionAction::class)->execute(new CreateNamedRevisionInput(node: $node, label: 'checkpoint', keepForever: true));
    $restored = app(RestoreDocumentRevisionAction::class)->execute(node: $node, versionId: $first->id, expectedStateVersion: 3);
    app(UpdateRevisionRetentionAction::class)->execute(node: $node, versionId: $restored->id);

    expect($node->refresh()->sequence)->toBe(0)
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(4);
});

it('keeps node and content validators independent: a rename bumps the node, not the content', function (): void {
    $node = legacySequenceDocument();
    $drive = $node->storage;

    Vaulter::drive($drive)->asSystem('validator independence')->nodes()->rename(node: $node, name: 'Renamed', expectedSequence: 0);
    app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node->refresh(),
        content: DocumentTestHelpers::content('after rename'),
        expectedStateVersion: 1,
    ));

    expect($node->refresh()->sequence)->toBe(1)
        ->and($node->name)->toBe('Renamed')
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(2);
});

it('touches the node change stamp without the sequence on a content write', function (): void {
    $node = legacySequenceDocument();
    $this->travel(5)->minutes();

    app(UpdateDocumentContentAction::class)->execute(new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('stamped'),
        actorId: 7,
    ));

    $fresh = $node->refresh();
    expect($fresh->sequence)->toBe(0)
        ->and((string) $fresh->updated_by)->toBe('7')
        ->and($fresh->updated_at?->greaterThan($node->created_at))->toBeTrue();
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
