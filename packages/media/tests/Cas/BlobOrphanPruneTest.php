<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\PhantomListingContentStore;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * The DESTRUCTIVE orphan prune (master plan P4 #11 / H.3): physically deleting true
 * orphans under the per-(scope,sha256) lock. It is only safe on a driver whose lock
 * serialises a write-less critical section — PostgreSQL's advisory xact lock or
 * MySQL's FOR UPDATE — so on SQLite the prune is REFUSED (covered in
 * BlobOrphanSweepTest). These cases pin the actual deletion + the lock-then-recheck
 * concurrency guard + idempotency, and therefore run ONLY on pgsql.
 *
 * The project CI is sqlite (the base TestCase forces an in-memory sqlite connection),
 * so EVERY test here SKIPS unless the live connection is pgsql — they are ready for
 * the future pgsql job (master plan P4 #11 TODO). Run them with the suite pointed at
 * a Postgres connection to exercise the real delete path.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    Carbon::setTestNow('2026-06-30 12:00:00');
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'orphan prune is PG/MySQL-only; needs the pgsql CI job (master plan P4 #11).',
);

// 11. Actual prune deletes the bytes.
it('prune на pgsql реально удаляет байты осиротевшего объекта', function (): void {
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'pg prune me', scope: 't-1', ageHours: 48);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: true);

    expect($report['pruneSupported'])->toBeTrue()
        ->and($report['pruned'])->toBe(1)
        ->and($report['orphans'])->toBe([$orphanKey]);
    Storage::disk('local')->assertMissing($orphanKey);
});

// 11b. Command-level: a prune that cleaned everything exits SUCCESS.
it('vaulter:blobs:reconcile --orphans --prune на pgsql очищает orphan и выходит SUCCESS', function (): void {
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'pg cmd prune', scope: 't-1', ageHours: 48);

    $this->artisan('vaulter:blobs:reconcile', ['--orphans' => true, '--prune' => true, '--orphan-grace' => 24])
        ->expectsOutputToContain('Orphan walk (pruned)')
        ->assertExitCode(Command::SUCCESS);

    Storage::disk('local')->assertMissing($orphanKey);
});

// 12. Concurrency / lock-then-recheck: a row that appears before the locked recheck
//     prevents the delete (the commit won the race for the content address).
it('lock-then-recheck на pgsql: строка, появившаяся до перепроверки под локом, спасает байты', function (): void {
    // A true parallel-connection interleave (commit racing the prune between the
    // cheap scan and the under-lock recheck) is impractical in a single-process
    // test. We pin the decisive invariant instead: the prune's under-lock probe
    // (findByStorageKey(lock: true)) is what gates the delete — so an owning row
    // present at recheck time means the bytes are NOT deleted.
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'commit won', scope: 't-1', ageHours: 48);

    // The "commit won" outcome: a committed row now owns this exact storage_key.
    // forceFill (P1.4/F-P1.5-04): Blob is fully guarded — bypassed here exactly
    // like BlobStoreRepository::insertCommitted() does.
    $winningBlob = new Blob;
    $winningBlob->forceFill([
        'tenant_id' => 1,
        'drive_id' => null,
        'hash_algo' => 'sha256',
        'sha256' => hash('sha256', 'commit won'),
        'size_bytes' => strlen('commit won'),
        'disk' => 'local',
        'storage_key' => $orphanKey,
        'status' => BlobStatus::Committed,
        'ref_count' => 0,
        'dedup_scope' => 'tenant',
        'scope_key' => 'tenant:1',
        'committed_at' => now(),
    ]);
    $winningBlob->save();

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: true);

    // Owned ⇒ never an orphan, never pruned; the committed bytes survive.
    expect($report['orphans'])->toBe([])
        ->and($report['pruned'])->toBe(0);
    Storage::disk('local')->assertExists($orphanKey);
});

// 13. Missing object on prune is idempotent: an object listed + past grace at scan
//     time but GONE by the time the under-lock physical delete fires (a concurrent
//     remove) must NOT throw — the delete is a harmless no-op.
//
//     A true between-scan-and-delete vanish is not single-process-reproducible against
//     the real disk (listKeys is a snapshot generator), so we drive it with a fixture
//     that lists a PHANTOM key (old mtime, no bytes): detection classifies it as an
//     orphan, then the prune's delete no-ops over the absent object.
it('prune на pgsql идемпотентен: исчезнувший до удаления объект не бросает (phantom listing)', function (): void {
    $phantomKey = app(ContentStore::class)->keyFor(scope: BlobScope::fromSegment('t-1'), sha256: hash('sha256', 'phantom'));

    app()->instance(ContentStore::class, new PhantomListingContentStore(
        phantomKey: $phantomKey,
        phantomMtime: now()->subHours(48)->getTimestamp(),
    ));

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: true);

    // No exception; the phantom is found as an orphan and its delete is a no-op.
    expect($report['orphans'])->toBe([$phantomKey])
        ->and($report['pruned'])->toBe(1);
});
