<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Fixtures\RevisionBlobPins;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Testing\Concurrency\ForkRace;

/**
 * Pair "purge of a drive file ↔ a concurrent ingest of the same bytes into the same scope" on PostgreSQL: the
 * purge holds the drive tree lock and unpins in its transaction, the ingest commits and pins under the blob lock. In
 * either order the surviving file's revision is pinned and its blob keeps its bytes through a full GC pass.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('keeps the bytes of an ingest that races the purge of a file with the same content', function (): void {
    $drive = DriveTestHelpers::drive();
    $doomed = $drive->files()->upload(UploadSource::fromString('shared content', 'old.txt'));
    $drive->nodes()->trash($doomed);
    $blob = Blob::query()->where('sha256', hash('sha256', 'shared content'))->firstOrFail();

    $outcomes = ForkRace::run([
        // The purge stalls inside its transaction, after the pins of the doomed revisions are gone.
        static function () use ($drive, $doomed): string {
            app(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query): void {
                if (str_starts_with(strtolower($query->sql), 'delete') && str_contains($query->sql, 'blob_references')) {
                    usleep(600_000);
                }
            });
            $drive->trash()->purge($doomed);

            return 'purged';
        },
        static function () use ($drive): string {
            usleep(150_000);

            return (string) $drive->files()->upload(UploadSource::fromString('shared content', 'new.txt'))->id;
        },
    ]);
    foreach ($outcomes as $outcome) {
        expect($outcome->ok)->toBeTrue($outcome->message ?? '');
    }

    app(BlobReconciler::class)->reconcile();
    app(BlobGarbageCollector::class)->collect(graceHours: 0);

    $fresh = $blob->fresh();
    expect(RevisionBlobPins::pins($fresh))->toBe(1)
        ->and($fresh->ref_count)->toBe(1)
        ->and($fresh->status)->toBe(BlobStatus::Committed)
        ->and(app(ContentStore::class)->exists($fresh->storage_key, $fresh->disk))->toBeTrue();
});
