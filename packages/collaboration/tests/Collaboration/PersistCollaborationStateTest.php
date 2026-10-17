<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Vaulter\Collaboration\Actions\PersistCollaborationStateAction;
use Vaulter\Collaboration\Data\PersistCollaborationStateInput;
use Vaulter\Collaboration\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Collaboration\Exceptions\CollaborationSeedConflictException;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Support\DocumentTestHelpers;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentStateTooLargeException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Models\Revisions\DocumentRevision;
use Vaulter\Drive\Models\Node;

/*
 * PersistCollaborationStateAction, the write path of the hub's store (until P5.7 replaces it): it fences the room by
 * generation, refuses what the document cannot take, and writes the Yjs state and its JSON projection through the
 * documents' state writer under the tree, node and document locks.
 */
beforeEach(function (): void {
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

function persist(Node $node, string $yjsState, ?array $content = null, ?int $expectedEpoch = null, bool $seed = false, ?int $expectedStateVersion = null): mixed
{
    return app(PersistCollaborationStateAction::class)->execute(new PersistCollaborationStateInput(
        node: $node, yjsState: $yjsState, content: $content, expectedEpoch: $expectedEpoch, seed: $seed, expectedStateVersion: $expectedStateVersion,
    ));
}

/** A document whose working state was restored from a revision written by a newer schema. */
function newerSchemaDocument(): Node
{
    $node = Hub::document(level: null);
    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('newer')));
    $newer = DocumentTestHelpers::head($node);
    app(VaulterDatabase::class)->write(fn () => DocumentRevision::query()->whereKey($newer->getKey())->update(['schema_version' => 99]));
    app(UpdateDocumentContentAction::class)->execute(DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('current')));
    app(RestoreDocumentRevisionAction::class)->execute(DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(node: $node, revision: $newer));

    return $node;
}

it('rejects a projection over max_document_size_kb, F-P1.11-05', function (): void {
    $node = Hub::document(level: null);
    config()->set('vaulter-documents.content.max_size_kb', 1);

    expect(fn () => persist($node->refresh(), 'small-yjs-state', DocumentTestHelpers::content(str_repeat('x', 4096))->toArray()))
        ->toThrow(InvalidDocumentContentException::class);
});

it('refuses a collaboration store once a newer schema is the working state', function (): void {
    $node = newerSchemaDocument();
    $before = DocumentTestHelpers::document($node);

    expect(fn () => persist($node, 'late-room', Hub::tiptap('late edit')))->toThrow(DocumentContentDegradedException::class);

    $after = DocumentTestHelpers::document($node);
    expect($after->state_version)->toBe($before->state_version)
        ->and($after->yjs_state)->toBe($before->yjs_state)
        ->and($after->revision_count)->toBe($before->revision_count);
});

it('fences a write from an old collaboration epoch before anything is written', function (): void {
    $node = Hub::document(level: null);

    expect(fn () => persist($node, "stale\0room", expectedEpoch: 5))->toThrow(CollaborationEpochMismatchException::class);

    $document = DocumentTestHelpers::document($node);
    expect($document->state_version)->toBe(1)
        ->and($document->yjs_state)->toBeNull();
});

it('counts Yjs and projection changes while an identical Yjs state is a no-op, raising one event per change', function (): void {
    $node = Hub::document(level: null);
    Event::fake([DocumentContentUpdated::class]);

    persist($node, "first\0state");
    persist($node, "second\0state");
    expect(DocumentTestHelpers::document($node)->state_version)->toBe(3);

    $same = persist($node, "second\0state");
    expect($same->changed)->toBeFalse()
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(3);
    Event::assertDispatchedTimes(DocumentContentUpdated::class, 2);

    persist($node, "second\0state", DocumentTestHelpers::content('projection changed')->toArray());
    $document = DocumentTestHelpers::document($node);
    expect($document->state_version)->toBe(4)
        ->and($document->yjs_state)->toBe("second\0state")
        ->and($document->collab_epoch)->toBe(0);
    Event::assertDispatchedTimes(DocumentContentUpdated::class, 3);
});

it('refuses a seed over an existing Yjs state and a seed built from a state version that moved on', function (): void {
    $node = Hub::document(level: null);

    persist($node, "room\0state", seed: true);
    expect(fn () => persist($node, "other\0state", seed: true))->toThrow(CollaborationSeedConflictException::class);

    $fresh = Hub::document(level: null);
    $stale = DocumentTestHelpers::document($fresh)->state_version - 1;
    expect(fn () => persist($fresh, "seed\0state", seed: true, expectedStateVersion: $stale))->toThrow(CollaborationSeedConflictException::class);
    expect(DocumentTestHelpers::document($fresh)->yjs_state)->toBeNull();
});

it('refuses a state over the largest one a store may write', function (): void {
    $node = Hub::document(level: null);
    config()->set('vaulter-collaboration.max_state_bytes', 8);

    expect(fn () => persist($node, str_repeat('x', 9)))->toThrow(DocumentStateTooLargeException::class);
});
