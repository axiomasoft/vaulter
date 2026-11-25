<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\NonListableContentStore;
use Vaulter\Media\Tests\Fixtures\Cas\NullMtimeContentStore;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/**
 * Orphan-object disk-walk: the inverse of reconcile() —
 * BlobReconciler::sweepOrphans() walks the DISK for bytes that match the committed
 * CAS key layout "<scope>/ab/cd/<sha256>" but have NO blob row (a commit that
 * promoted bytes then rolled its row back, or leaked bytes), and — only on a driver
 * whose lock serialises a write-less critical section — physically prunes the true
 * orphans under the same per-(scope,sha256) lock + grace discipline the GC uses.
 *
 * These cases pin the SQLite-testable half: detection on every driver, the seven
 * round-trip guards that keep the sweep from EVER touching a non-canonical path,
 * off scope, any-status ⇒ owned, the disk-agnostic ownership probe (the
 * data-loss fix), the orphan grace (fresh / aged / null-mtime), prune REFUSED on
 * sqlite, and the listing-unsupported refusal. The actual destructive prune is
 * PG/MySQL-only and lives in BlobOrphanPruneTest (skipped on sqlite).
 *
 * Time is frozen so the grace arithmetic (mtime < now − graceHours) is deterministic;
 * object age is set by touch()-ing the faked disk's real path.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
    Carbon::setTestNow('2026-06-30 12:00:00');
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. Detection (report-only) — the happy path on every driver.
// ─────────────────────────────────────────────────────────────────────────────
it('finds a real orphan and skips a committed object (report only)', function (): void {
    // A real committed blob (row + bytes) — must be SKIPPED as owned.
    $blob = CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor('owned bytes'), scope: BlobScope::forUpload((string) config('vaulter-media.dedup.scope'), (string) 1, 'unused')));

    // A genuine orphan: valid canonical key, no row, aged past grace.
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'orphan bytes', scope: 't-1', ageHours: 48);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: false);

    expect($report['listingSupported'])->toBeTrue()
        ->and($report['orphans'])->toBe([$orphanKey])
        ->and($report['orphans'])->not->toContain($blob->storage_key)
        ->and($report['scanned'])->toBe(2)   // the committed object + the orphan
        ->and($report['skipped'])->toBe(1)   // the committed object
        ->and($report['pruned'])->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Round-trip guard — every non-canonical path is SKIPPED and survives.
//    Each spoof is its own assertion via a dataset (one scenario, many inputs).
// ─────────────────────────────────────────────────────────────────────────────
it('round-trip guard: a NON-canonical path is skipped and survives the walk', function (string $label, Closure $makePath): void {
    $sha = hash('sha256', "guard:{$label}");
    $path = $makePath($sha);

    Storage::disk('local')->put($path, "bytes:{$label}");
    // Age it well past grace so ONLY the path-guard (not the grace) can exclude it.
    CasTestHelpers::ageObject(key: $path, ageHours: 72);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: false);

    expect($report['orphans'])->toBe([])            // never classified as an orphan
        ->and($report['scanned'])->toBe(1)
        ->and($report['skipped'])->toBe(1);
    Storage::disk('local')->assertExists($path);    // and never touched
})->with([
    // Staging temp object — the resumable/multipart landing zone, no scope prefix.
    'staging tmp/<sha>' => ['tmp', fn (string $sha): string => "tmp/{$sha}"],
    // A resumable-style part fragment hanging off a canonical-looking prefix.
    'resumable part' => ['part', fn (string $sha): string => 't1/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2)."/{$sha}/part-1"],
    // A foreign file that has nothing to do with the CAS layout.
    'foreign file' => ['foreign', fn (): string => 'docs/readme.md'],
    // MIS-SHARDED: a real-looking key whose ab/cd shard is NOT this sha's shard, so
    // keyFor() re-derivation fails the round-trip even though the regex matches.
    'mis-sharded shard' => ['missharded', fn (string $sha): string => "global/00/00/{$sha}"],
    // UPPERCASE hex digest — the layout demands lowercase; the regex rejects it.
    'uppercase hex' => ['upper', fn (string $sha): string => 't1/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.strtoupper($sha)],
    // An extra path segment past the digest.
    'extra segment' => ['extra', fn (string $sha): string => 't1/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2)."/{$sha}/extra"],
    // A bare 64-hex with no scope/shard structure at all.
    'bare 64-hex' => ['bare', fn (string $sha): string => $sha],
]);

// ─────────────────────────────────────────────────────────────────────────────
// 3. off scope — an orphan of its own scope IS detected.
// ─────────────────────────────────────────────────────────────────────────────
it('off scope: the orphan of a separate upload (dedup off) is found', function (): void {
    $segment = BlobScope::unshared()->segment();
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'off orphan', scope: $segment, ageHours: 48);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: false);

    expect($orphanKey)->toStartWith($segment.'/')
        ->and($report['orphans'])->toContain($orphanKey);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Any-status row ⇒ owned (never an orphan), for every lifecycle status.
// ─────────────────────────────────────────────────────────────────────────────
it('any row status makes the object owned (skipped), not an orphan', function (BlobStatus $status): void {
    $blob = CasTestHelpers::seedBlobRow(bytes: "owned:{$status->value}", status: $status, disk: 'local');
    // Age the bytes past grace so ONLY the row-ownership (not the grace) can exclude.
    CasTestHelpers::ageObject(key: $blob->storage_key, ageHours: 72);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: false);

    expect($report['orphans'])->toBe([])
        ->and($report['skipped'])->toBe(1);
    Storage::disk('local')->assertExists($blob->storage_key);
})->with([
    'pending' => [BlobStatus::Pending],
    'committed' => [BlobStatus::Committed],
    'tombstoned' => [BlobStatus::Tombstoned],
    'deleting' => [BlobStatus::Deleting],
    'deleted' => [BlobStatus::Deleted],
]);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Disk-agnostic ownership regression (review Finding A) — the data-loss fix.
//    Row records disk='other', bytes live on the scanned disk 'local': the row
//    probe must still see the object as OWNED (storage_key is globally unique).
// ─────────────────────────────────────────────────────────────────────────────
it('owned across disks: a row on disk=other with bytes on local is NOT an orphan (storage_key is globally unique)', function (): void {
    // Bytes on 'local' (the disk we will scan); the owning row records disk='other'.
    $blob = CasTestHelpers::seedBlobRow(
        bytes: 'cross-disk owned',
        status: BlobStatus::Committed,
        disk: 'other',
        bytesDisk: 'local',
    );
    CasTestHelpers::ageObject(key: $blob->storage_key, ageHours: 72);

    expect($blob->disk)->toBe('other');

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: false);

    // If the probe re-added the disk filter it would read this as row-less and
    // (under prune) delete LIVE bytes — this assertion is the guard against that.
    expect($report['orphans'])->toBe([])
        ->and($report['skipped'])->toBe(1);
    Storage::disk('local')->assertExists($blob->storage_key);
});

// ─────────────────────────────────────────────────────────────────────────────
// 6. Orphan grace — fresh skipped, aged detected, null-mtime skipped.
// ─────────────────────────────────────────────────────────────────────────────
it('grace: a fresh row-less object (mtime=now) is skipped', function (): void {
    // Fresh: no ageing — mtime is "now", inside any grace window.
    CasTestHelpers::seedOrphan(bytes: 'fresh orphan', scope: 't-1', ageHours: 0);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: false);

    expect($report['orphans'])->toBe([])
        ->and($report['skipped'])->toBe(1);
});

it('grace: the same object aged past --orphan-grace lands in the orphans', function (): void {
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'aged orphan', scope: 't-1', ageHours: 48);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: false);

    expect($report['orphans'])->toBe([$orphanKey]);
});

it('grace: an object with a null mtime (age unknown) is skipped, not deleted', function (): void {
    // The real store lays the bytes down (a valid canonical key, no row)...
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'null mtime orphan', scope: 't-1', ageHours: 48);

    // ...but the ACTIVE store reports a null lastModified for everything, so the
    // grace gate cannot establish the age ⇒ the object is skipped, never an orphan.
    app()->instance(ContentStore::class, new NullMtimeContentStore);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: false);

    expect($report['listingSupported'])->toBeTrue()  // it IS listable...
        ->and($report['scanned'])->toBe(1)           // ...the object was enumerated...
        ->and($report['orphans'])->toBe([])          // ...but age-unknown ⇒ skipped.
        ->and($report['skipped'])->toBe(1);
    Storage::disk('local')->assertExists($orphanKey);
});

// ─────────────────────────────────────────────────────────────────────────────
// 7. Prune REFUSED on sqlite — detection runs, deletion does not.
// ─────────────────────────────────────────────────────────────────────────────
it('prune is refused on sqlite: pruneSupported=false, the bytes live and the orphan stays in the report', function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite'); // the gate's premise

    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'survives prune', scope: 't-1', ageHours: 48);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: true);

    expect($report['pruneSupported'])->toBeFalse()
        ->and($report['pruned'])->toBe(0)
        ->and($report['orphans'])->toBe([$orphanKey]);
    Storage::disk('local')->assertExists($orphanKey);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'sqlite',
    'prune-refusal is the SQLite premise; PG/MySQL support prune (covered in BlobOrphanPruneTest).',
);

// ─────────────────────────────────────────────────────────────────────────────
// 8. Listing unsupported — a non-listable store refuses the walk cleanly.
// ─────────────────────────────────────────────────────────────────────────────
it('listing unsupported: a non-listable store gives listingSupported=false, scanned=0 and deletes nothing', function (): void {
    // Bytes are a genuine, aged orphan — but the active store cannot enumerate.
    $orphanKey = CasTestHelpers::seedOrphan(bytes: 'unwalkable orphan', scope: 't-1', ageHours: 48);

    app()->instance(ContentStore::class, new NonListableContentStore);

    $report = app(BlobReconciler::class)->sweepOrphans(disk: 'local', graceHours: 24, prune: true);

    expect($report['listingSupported'])->toBeFalse()
        ->and($report['pruneSupported'])->toBeFalse()
        ->and($report['scanned'])->toBe(0)
        ->and($report['orphans'])->toBe([])
        ->and($report['pruned'])->toBe(0);
    Storage::disk('local')->assertExists($orphanKey); // nothing deleted
});
