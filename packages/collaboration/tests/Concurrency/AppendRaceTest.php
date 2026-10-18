<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Collaboration\Tests\Collaboration\HubTestHelpers as Hub;
use Vaulter\Collaboration\Tests\Concurrency\RaceFixtures;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * Scenario 3 on PostgreSQL (C3): two hubs append different updates of the same generation at once, the first append of
 * the generation included (both may try to create the head). The document lock and the head row serialize them: the
 * seqs are 1 and 2 without a gap, both have a receipt, and the marker ends at the higher one.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this, app(VaulterDatabase::class)->connection()->getName());
    Hub::configure();
});

it('gives two concurrent appends of one generation the seqs 1 and 2, each with its receipt', function (): void {
    $node = RaceFixtures::document();
    $nodeId = $node->getKey();
    $append = static fn (string $payload): Closure => static fn (): array => RaceFixtures::attempt(static function () use ($nodeId, $payload): void {
        app(UpdateLog::class)->append($nodeId, new UpdateEnvelope(
            generation: 0,
            updateId: (string) Str::uuid(),
            payload: $payload,
            claimedSha256: hash('sha256', $payload),
        ));
    });

    $results = RaceFixtures::race([$append('hub A'), $append('hub B')]);

    $tables = app(DatabaseConfig::class);
    $seqs = DB::table($tables->table('collaboration_receipts'))->orderBy('seq')->pluck('seq')->map(static fn (mixed $seq): int => (int) $seq)->all();
    $document = Hub::aggregate($node);
    expect(array_column($results, 'kind'))->toBe(['committed', 'committed'])
        ->and($seqs)->toBe([1, 2])
        ->and(DB::table($tables->table('collaboration_updates'))->count())->toBe(2)
        ->and((int) DB::table($tables->table('collaboration_heads'))->value('head_seq'))->toBe(2)
        ->and(DB::table($tables->table('collaboration_heads'))->count())->toBe(1)
        ->and([$document->pending_generation, $document->pending_through_seq])->toBe([0, 2]);
});
