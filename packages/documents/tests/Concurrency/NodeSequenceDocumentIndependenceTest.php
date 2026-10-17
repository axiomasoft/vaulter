<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Documents\Tests\Fixtures\StateWrites;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * The node validator and the content validators do not invalidate each other: a rename
 * that read sequence N succeeds while a content writer commits in parallel, and only
 * the rename moves the sequence.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
});

dataset('content writers', [
    'collaboration store' => ['store', 2, 0],
    'JSON update' => ['update', 2, 0],
    'restore' => ['restore', 2, 1],
]);

it('lets a rename with expectedSequence succeed beside a parallel content write: :dataset', function (string $writer, int $stateVersion, int $epoch): void {
    $node = RaceFixtures::document(sequence: 7);
    $drive = $node->storage;
    $first = DocumentTestHelpers::head($node);
    $content = static fn (): Closure => match ($writer) {
        'store' => static fn () => StateWrites::yjs($node, "actual\0Yjs\0snapshot", GrantedDocuments::tiptap('room edit')),
        'update' => static fn () => app(UpdateDocumentContentAction::class)->execute(
            DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('race edit'), expectedStateVersion: 1),
        ),
        'restore' => static fn () => app(RestoreDocumentRevisionAction::class)->execute(
            DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(node: $node, revision: $first, expectedStateVersion: 1),
        ),
    };

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt($content()),
        static fn (): array => RaceFixtures::attempt(static fn () => Vaulter::drive($drive)->asSystem('validator independence race')
            ->nodes()->rename(node: $node->getKey(), name: 'race rename', expectedSequence: 7)),
    ]);

    $document = DocumentTestHelpers::document($node);
    expect(array_column($results, 'kind'))->toBe(['committed', 'committed'])
        // Restore raises two content events (revision created + restored), the rest one.
        ->and($results[0]['events'])->toBe($writer === 'restore' ? 2 : 1)
        ->and($results[1]['events'])->toBe(1)
        // Only the rename moves the node validator; only the content writer moves the content ones.
        ->and($node->refresh()->sequence)->toBe(8)
        ->and($node->name)->toBe('race rename')
        ->and($document->state_version)->toBe($stateVersion)
        ->and($document->collab_epoch)->toBe($epoch)
        ->and($drive->refresh()->used_bytes)->toBe(0);
})->with('content writers');
