<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\RestoreDocumentRevisionInput;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * Two writers that read state version N and both ask to write on top of it: exactly one
 * commit N+1, the other is refused with 412 document_state_mismatch and leaves no
 * content, checkpoint or event behind.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
    // Every effective write is also a checkpoint, so "no writes" shows in the revision count.
    config()->set('vaulter-documents.revisions.checkpoint_interval_seconds', 0);
});

it('lets one of two updates with expectedStateVersion N through and refuses the other without a write', function (): void {
    $node = RaceFixtures::document();
    $update = static fn (string $text): Closure => static fn (): array => RaceFixtures::attempt(static fn () => app(UpdateDocumentContentAction::class)->execute(
        DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content($text), expectedStateVersion: 1),
    ));

    $results = RaceFixtures::race([$update('writer A'), $update('writer B')]);

    $kinds = array_column($results, 'kind');
    sort($kinds);
    $winner = $results[array_search('committed', array_column($results, 'kind'), true)];
    $loser = $results[array_search('document_state_mismatch', array_column($results, 'kind'), true)];
    $document = DocumentTestHelpers::document($node);
    expect($kinds)->toBe(['committed', 'document_state_mismatch'])
        ->and($loser['status'])->toBe(412)
        ->and($loser['events'])->toBe(0)
        // The winner's update raises its single event; the refused one raises none.
        ->and($winner['events'])->toBe(1)
        ->and($document->state_version)->toBe(2)
        ->and($document->collab_epoch)->toBe(0)
        ->and($document->revision_count)->toBe(2)
        ->and(DocumentTestHelpers::revisions($node))->toHaveCount(2)
        ->and($document->tipTapContent()->toArray())->toBeIn([
            DocumentTestHelpers::content('writer A')->toArray(),
            DocumentTestHelpers::content('writer B')->toArray(),
        ]);
});

it('shares the validator between an update and a restore: one wins, the other is refused', function (): void {
    $node = RaceFixtures::document();
    $first = DocumentTestHelpers::head($node);

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(UpdateDocumentContentAction::class)->execute(
            DocumentTestHelpers::context($node), new UpdateDocumentContentInput(node: $node, content: DocumentTestHelpers::content('edit'), expectedStateVersion: 1),
        )),
        static fn (): array => RaceFixtures::attempt(static fn () => app(RestoreDocumentRevisionAction::class)->execute(
            DocumentTestHelpers::context($node), new RestoreDocumentRevisionInput(node: $node, revision: $first, expectedStateVersion: 1),
        )),
    ]);

    $kinds = array_column($results, 'kind');
    sort($kinds);
    $document = DocumentTestHelpers::document($node);
    expect($kinds)->toBe(['committed', 'document_state_mismatch'])
        ->and($document->state_version)->toBe(2)
        ->and($document->revision_count)->toBe(2)
        // Only a restore moves the epoch; whoever lost wrote nothing.
        ->and($document->collab_epoch)->toBe($results[1]['kind'] === 'committed' ? 1 : 0);
});
