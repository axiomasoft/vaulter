<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Fixtures\RevisionBlobPins;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Testing\Concurrency\ForkRace;

/*
 * I18 "GC ∥ a new revision" (D23, p6-design#gc-scrub) on PostgreSQL: a new revision of an existing file whose bytes a
 * collector is tombstoning or sweeping ends up pinned and with its bytes, in every order: (a) a sweep paused between
 * the unlink and its end, committing or rolling back; (b) a mark and the pin of the revision in either order; (c) a
 * collector running with the drive switched off while another module's retained pin holds the blob. Real processes
 * (ForkRace); serial + database, engine pgsql — written and classified in PLAN3, run in PLAN4 (D41). The slow side
 * pauses INSIDE its transaction with its locks held; the other side starts while it waits. The deterministic half is
 * packages/media/tests/Cas/BlobGcCriteriaTest.php.
 */

const GC_REVISION_RACE_PAUSE_US = 800_000;

const GC_REVISION_RACE_HEAD_START_US = 200_000;

const GC_REVISION_RACE_RETAINED_OWNER = 'archive.retained_copy';

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

/**
 * An existing file that will receive the new revision, and the blob of $bytes left unpinned by the purge of another
 * file (the drive's only holder of those bytes), its ref_count cache rebuilt by the reconciler.
 *
 * @param  (Closure(Blob): void)|null                      $beforePurge pins the blob for another holder while the drive still holds it
 * @return array{0: DriveHandle, 1: NodeSnapshot, 2: Blob}
 */
function gcRevisionRaceSetup(string $bytes, ?Closure $beforePurge = null): array
{
    $drive = DriveTestHelpers::drive();
    $target = $drive->files()->upload(UploadSource::fromString('the first revision of the target', 'target.txt'));
    $doomed = $drive->files()->upload(UploadSource::fromString($bytes, 'doomed.txt'));
    $blob = Blob::query()->where('sha256', hash('sha256', $bytes))->firstOrFail();

    if ($beforePurge !== null) {
        $beforePurge($blob);
    }

    $drive->nodes()->trash($doomed);
    $drive->trash()->purge($doomed);
    app(BlobReconciler::class)->reconcile();

    expect(RevisionBlobPins::pins($blob))->toBe(0);

    return [$drive, $target, $blob->fresh()];
}

/** The new revision of $target with $bytes, through the drive's own ingest (commit + pin in one transaction). */
function gcRevisionRaceAdd(DriveHandle $drive, NodeSnapshot $target, string $bytes): string
{
    return $drive->files()->addRevision($target, UploadSource::fromString($bytes, 'target.txt'))->id;
}

/** Hold the drive's transaction open right after it wrote its pin (with the blob lock it took to commit the bytes). */
function gcRevisionRacePauseAfterPin(): void
{
    app(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query): void {
        if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'blob_references')) {
            usleep(GC_REVISION_RACE_PAUSE_US);
        }
    });
}

/** After a full GC pass the blob is the revision's, committed, cache-consistent and holds exactly $bytes. */
function gcRevisionRaceAssertKept(Blob $blob, string $bytes, int $pins = 1): void
{
    app(BlobReconciler::class)->reconcile();
    $gc = app(BlobGarbageCollector::class);
    $gc->collect(graceHours: 1);
    Carbon::setTestNow(now()->addHours(2)); // past the grace of anything the first pass tombstoned
    $gc->collect(graceHours: 1);

    $fresh = $blob->fresh();
    expect(RevisionBlobPins::pins($fresh))->toBe(1)
        ->and($fresh->ref_count)->toBe($pins)
        ->and($fresh->status)->toBe(BlobStatus::Committed)
        ->and($fresh->delete_after_at)->toBeNull()
        ->and(app(ContentStore::class)->exists($fresh->storage_key, $fresh->disk))->toBeTrue()
        ->and(stream_get_contents(app(BlobStore::class)->read($fresh)))->toBe($bytes);
}

it('(a) pins a new revision with the bytes a sweep paused between the unlink and its end is collecting', function (bool $sweepCommits): void {
    $bytes = 'gc race (a) '.($sweepCommits ? 'sweep commits' : 'sweep rolls back');
    [$drive, $target, $blob] = gcRevisionRaceSetup($bytes);
    expect(app(BlobGarbageCollector::class)->mark(graceHours: 1))->toBe(['tombstoned' => 1, 'skipped' => 0]);
    $blob->fresh()->forceFill(['delete_after_at' => now()->subMinute()])->save();

    $outcomes = ForkRace::run([
        // The sweep stalls after the unlink, once it wrote `deleted`, still inside its transaction and its lock.
        static function () use ($sweepCommits): array {
            app(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query) use ($sweepCommits): void {
                if (str_starts_with(strtolower($query->sql), 'update') && in_array(BlobStatus::Deleted->value, $query->bindings, true)) {
                    usleep(GC_REVISION_RACE_PAUSE_US);
                    if (!$sweepCommits) {
                        throw new RuntimeException('sweep rolled back after the unlink');
                    }
                }
            });

            return app(BlobGarbageCollector::class)->sweep();
        },
        // The revision's commit waits for the sweep's lock, then reads deleted or a tombstone without bytes and
        // promotes the bytes again before it pins.
        static function () use ($drive, $target, $bytes): string {
            usleep(GC_REVISION_RACE_HEAD_START_US);

            return gcRevisionRaceAdd($drive, $target, $bytes);
        },
    ]);

    expect($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and($sweepCommits ? $outcomes[0]->ok : $outcomes[0]->failedWith(RuntimeException::class))->toBeTrue($outcomes[0]->message ?? '');
    if ($sweepCommits) {
        expect($outcomes[0]->value)->toBe(['swept' => 1, 'skipped' => 0]);
    }

    // The same row serves the revision: resurrected from deleted, or from the tombstone the rollback restored.
    expect(Blob::query()->where('sha256', $blob->sha256)->count())->toBe(1);
    gcRevisionRaceAssertKept($blob, $bytes);
})->with(['sweep commits' => true, 'sweep rolls back' => false]);

it('(b) orders the mark of a blob and the pin of a new revision with its bytes in either direction', function (bool $markFirst): void {
    $bytes = 'gc race (b) '.($markFirst ? 'mark first' : 'pin first');
    [$drive, $target, $blob] = gcRevisionRaceSetup($bytes);
    expect($blob->status)->toBe(BlobStatus::Committed)->and($blob->ref_count)->toBe(0);

    $outcomes = ForkRace::run([
        static function () use ($markFirst): array {
            if (!$markFirst) {
                usleep(GC_REVISION_RACE_HEAD_START_US);
            }

            return app(VaulterDatabase::class)->transaction(static function () use ($markFirst): array {
                $result = app(BlobGarbageCollector::class)->mark(graceHours: 24);
                if ($markFirst) {
                    usleep(GC_REVISION_RACE_PAUSE_US);
                }

                return $result;
            });
        },
        static function () use ($drive, $target, $bytes, $markFirst): string {
            if ($markFirst) {
                usleep(GC_REVISION_RACE_HEAD_START_US);
            } else {
                gcRevisionRacePauseAfterPin();
            }

            return gcRevisionRaceAdd($drive, $target, $bytes);
        },
    ]);

    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        // Mark first: it tombstoned, the revision's commit resurrected the row. Pin first: the mark saw the pin.
        ->and($outcomes[0]->value['tombstoned'])->toBe($markFirst ? 1 : 0);
    gcRevisionRaceAssertKept($blob, $bytes);
})->with(['mark first' => true, 'pin first' => false]);

it('(c) keeps the bytes for a new revision and the retained pin while a collector runs with the drive switched off', function (bool $gcFirst): void {
    $bytes = 'gc race (c) '.($gcFirst ? 'gc first' : 'revision first');
    // Another holder keeps the blob: a module that is neither registered nor enabled in this application.
    [$drive, $target, $blob] = gcRevisionRaceSetup($bytes, static function (Blob $blob): void {
        app(VaulterDatabase::class)->transaction(fn () => app(BlobPins::class)->pin($blob, GC_REVISION_RACE_RETAINED_OWNER, 'copy-1'));
    });
    $blob->forceFill(['ref_count' => 0])->save(); // a stale cache: GC reads the pins under the lock

    $outcomes = ForkRace::run([
        static function () use ($gcFirst): array {
            // The collector's host has the drive disabled; liveness is the pins of media, never the drive's state.
            config()->set('vaulter.modules.drive', false);
            if (!$gcFirst) {
                usleep(GC_REVISION_RACE_HEAD_START_US);
            }

            return app(VaulterDatabase::class)->transaction(static function () use ($gcFirst): array {
                $result = app(BlobGarbageCollector::class)->collect(graceHours: 1);
                if ($gcFirst) {
                    usleep(GC_REVISION_RACE_PAUSE_US);
                }

                return $result;
            });
        },
        static function () use ($drive, $target, $bytes, $gcFirst): string {
            if ($gcFirst) {
                usleep(GC_REVISION_RACE_HEAD_START_US);
            } else {
                gcRevisionRacePauseAfterPin();
            }

            return gcRevisionRaceAdd($drive, $target, $bytes);
        },
    ]);

    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and($outcomes[0]->value['tombstoned'])->toBe(0)
        ->and($outcomes[0]->value['swept'])->toBe(0);
    gcRevisionRaceAssertKept($blob, $bytes, pins: 2);
    expect(app(BlobPins::class)->countByOwner())->toMatchArray([GC_REVISION_RACE_RETAINED_OWNER => 1, 'drive.revision_blob' => 2]);
})->with(['gc first' => true, 'revision first' => false]);
