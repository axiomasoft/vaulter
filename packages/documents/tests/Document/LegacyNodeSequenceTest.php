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
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Data\Revisions\UpdateRevisionRetentionInput;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\Types\KeyedType;
use Vaulter\Exceptions\Registry\RegistryFrozenException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\Documents\FakeExporter;

function legacySequenceDocument(): Node
{
    $drive = DocumentTestHelpers::drive();

    return app(CreateDocumentAction::class)->execute(DocumentTestHelpers::context($drive->model()), new CreateDocumentInput(
        storage: $drive->model(),
        name: 'Sequence document',
        content: DocumentTestHelpers::content('initial'),
        parent: DriveTestHelpers::model($drive->root()),
    ));
}

it('leaves node.sequence alone on every document content write and counts them in state_version (D35)', function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
    $node = legacySequenceDocument();
    $first = DocumentTestHelpers::head($node);
    expect($node->refresh()->sequence)->toBe(0)
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(1);

    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('first'),
        expectedStateVersion: 1,
    ));
    app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(node: $node, yjsState: "yjs\0state"));
    app(CreateNamedRevisionAction::class)->execute(DocumentTestHelpers::context($node), new CreateNamedRevisionInput(node: $node, label: 'checkpoint', keepForever: true));
    $restored = app(RestoreDocumentRevisionAction::class)->execute(DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(node: $node, revision: $first->id, expectedStateVersion: 3));
    app(UpdateRevisionRetentionAction::class)->execute(DocumentTestHelpers::context($node), new UpdateRevisionRetentionInput(node: $node, revision: $restored->id, keepForever: false));

    expect($node->refresh()->sequence)->toBe(0)
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(4);
});

it('keeps node and content validators independent: a rename bumps the node, not the content', function (): void {
    $node = legacySequenceDocument();
    $drive = $node->storage;

    Vaulter::drive($drive)->asSystem('validator independence')->nodes()->rename(node: $node->getKey(), name: 'Renamed', expectedSequence: 0);
    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node->refresh()), new UpdateDocumentContentInput(
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

    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node, 7), new UpdateDocumentContentInput(
        node: $node,
        content: DocumentTestHelpers::content('stamped'),
    ));

    $fresh = $node->refresh();
    expect($fresh->sequence)->toBe(0)
        ->and((string) $fresh->updated_by)->toBe('7')
        ->and($fresh->updated_at?->greaterThan($node->created_at))->toBeTrue();
});

it('owns the document type registry and exporter registrations in the documents manager', function (): void {
    // Types register while the application boots; afterwards the set is frozen (PLAN2.P4.3).
    expect(array_keys(Documents::types()->all()))->toBe(['vaulter/markdown', 'vaulter/rich-text'])
        ->and(fn () => Documents::types()->register(new KeyedType('acme/sheet')))->toThrow(RegistryFrozenException::class);

    // Exporters are frozen with the registry once the application has booted.
    expect(Documents::exporters()->formatsFor('vaulter/rich-text'))->toBe(['html', 'markdown'])
        ->and(Documents::exporters()->for('vaulter/markdown', 'html')->key())->toBe('vaulter/html')
        ->and(fn () => Documents::exporters()->register(FakeExporter::class))->toThrow(RegistryFrozenException::class);
});
