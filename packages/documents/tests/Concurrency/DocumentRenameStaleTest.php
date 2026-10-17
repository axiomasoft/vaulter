<?php

declare(strict_types=1);

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Tests\Concurrency\RaceFixtures;
use Vaulter\Documents\Tests\Document\DocumentTestHelpers;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Testing\Concurrency\ForkRace;

/*
 * V36 for a document: two clients rename the same document with the same expected
 * sequence — the first commits and moves the sequence, the second is refused with 412
 * node_sequence_mismatch and changes nothing.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
});

it('V36 refuses the second of two renames with the same expectedSequence', function (): void {
    $node = RaceFixtures::document();
    $drive = $node->storage;
    $rename = static fn (string $name): Closure => static fn (): array => RaceFixtures::attempt(static fn () => Vaulter::drive($drive)->asSystem('V36 document rename')
        ->nodes()->rename(node: $node->getKey(), name: $name, expectedSequence: 0));

    $results = RaceFixtures::race([$rename('Client A'), $rename('Client B')]);

    $kinds = array_column($results, 'kind');
    sort($kinds);
    $loser = $results[array_search('node_sequence_mismatch', array_column($results, 'kind'), true)];
    $winner = $results[array_search('committed', array_column($results, 'kind'), true)];
    expect($kinds)->toBe(['committed', 'node_sequence_mismatch'])
        ->and($loser['status'])->toBe(412)
        ->and($loser['events'])->toBe(0)
        ->and($winner['events'])->toBe(1)
        ->and($node->refresh()->sequence)->toBe(1)
        ->and($node->name)->toBeIn(['Client A', 'Client B'])
        // A rename is not a content write.
        ->and(DocumentTestHelpers::document($node)->state_version)->toBe(1);
});
