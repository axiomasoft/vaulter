<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\State\CheckpointPublication;
use Vaulter\Documents\State\CollaborativeState;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Documents\Tests\Fixtures\GrantedDocuments;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * C5 on PostgreSQL (P5.3): the hub has read head H of generation G and publishes a checkpoint through H while an
 * append commits H + 1 and marks it. Whichever commits first, the marker ends at (G, H + 1): a publication clears only
 * what it covers, so the appended edit is never reported as in the working state. Both contenders commit — the marker
 * is not a validator, the publication's CAS is on the state version.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
});

it('keeps the marker of an append racing a publication of the head it read', function (): void {
    $node = RaceFixtures::document();
    $nodeId = $node->getKey();
    $read = app(VaulterDatabase::class)->transaction(static function () use ($nodeId) {
        $state = app(CollaborativeState::class);
        $locked = $state->lock($nodeId);
        $state->markPending($locked, $locked->generation, 3);

        return $locked;
    });

    $results = RaceFixtures::race([
        static fn (): array => RaceFixtures::attempt(static fn () => app(VaulterDatabase::class)->transaction(static function () use ($nodeId, $read): void {
            $state = app(CollaborativeState::class);
            $state->markPending($state->lock($nodeId), $read->generation, 4);
        })),
        static fn (): array => RaceFixtures::attempt(static fn () => app(VaulterDatabase::class)->transaction(static function () use ($nodeId, $read): void {
            $state = app(CollaborativeState::class);
            $outcome = $state->publishCheckpoint($state->lock($nodeId), new CheckpointPublication(
                generation: $read->generation,
                expectedStateVersion: $read->stateVersion,
                coveredThrough: 3,
                yjsState: 'merged through 3',
                content: GrantedDocuments::tiptap('Merged'),
            ));
            if (!$outcome->published) {
                throw new RuntimeException('the publication lost its CAS');
            }
        })),
    ]);

    $document = DocumentTestHelpers::document($node);
    expect(array_column($results, 'kind'))->toBe(['committed', 'committed'])
        ->and([$document->pending_generation, $document->pending_through_seq])->toBe([$read->generation, 4])
        ->and($document->yjs_state)->toBe('merged through 3')
        ->and($document->state_version)->toBe($read->stateVersion + 1)
        ->and($document->collab_epoch)->toBe($read->generation);
});
