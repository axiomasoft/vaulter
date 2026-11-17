<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/*
 * The GC criteria, the deterministic half of the GC race tests: a blob is deleted only without a pin,
 * as a tombstone past its deadline or as a pending row past the orphan grace; a digest recorded with another size
 * refuses the ingest; a pin of a pending row is refused, never lost; the same bytes in two scopes are two blobs; and
 * pins of a disabled or absent module keep their bytes. The concurrent half is
 * packages/drive/tests/Concurrency/GcNewRevisionRaceTest.php and PinSweepRaceTest.php (pgsql).
 */

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

function gcCriteriaCommit(string $bytes, BlobScope $scope): Blob
{
    return CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), $scope));
}

/** Commit the bytes and pin what comes back in one owner transaction, as an owner ingests. */
function gcCriteriaIngest(string $bytes, BlobScope $scope, string $reference): Blob
{
    return app(VaulterDatabase::class)->transaction(function () use ($bytes, $scope, $reference): Blob {
        $blob = gcCriteriaCommit($bytes, $scope);
        app(BlobPins::class)->pin($blob->getKey(), CasTestHelpers::OWNER, $reference);

        return $blob;
    });
}

function gcCriteriaContents(Blob $blob): string|false
{
    return Storage::disk('local')->get($blob->storage_key) ?? false;
}

it('refuses an ingest whose digest a row records with another size and overwrites nothing', function (BlobStatus $status, bool $objectKept): void {
    $scope = BlobScope::tenant('5');
    $blob = gcCriteriaCommit('collide me', $scope);
    // A colliding digest cannot be produced: the row is bent to another size and its object to other content.
    $blob->forceFill([
        'size_bytes' => strlen('collide me') + 7,
        'status' => $status,
        'delete_after_at' => $status === BlobStatus::Tombstoned ? now()->subDay() : null,
    ])->save();
    $objectKept
        ? Storage::disk('local')->put($blob->storage_key, 'the other content of that digest')
        : Storage::disk('local')->delete($blob->storage_key);
    $before = $blob->fresh();

    expect(fn (): Blob => gcCriteriaIngest('collide me', $scope, 'collider'))->toThrow(RuntimeException::class, 'the ingest is refused');

    $after = $blob->fresh();
    expect($after->status)->toBe($status)
        ->and($after->size_bytes)->toBe(strlen('collide me') + 7)
        ->and($after->delete_after_at?->toIso8601String())->toBe($before->delete_after_at?->toIso8601String())
        ->and(CasTestHelpers::pins($blob))->toBe(0)
        ->and(Blob::query()->where('sha256', $blob->sha256)->count())->toBe(1);
    $objectKept
        ? expect(gcCriteriaContents($blob))->toBe('the other content of that digest')
        : Storage::disk('local')->assertMissing($blob->storage_key);
})->with([
    'committed, object present' => [BlobStatus::Committed, true],
    'tombstoned, not resurrected' => [BlobStatus::Tombstoned, true],
    'deleted, not promoted' => [BlobStatus::Deleted, false],
]);

it('refuses a pin of a pending row, loses nothing, and lands the commit after the pending sweep', function (): void {
    config()->set('vaulter-media.gc.orphan_grace_hours', 2);
    $scope = BlobScope::fromSegment('t-default');
    $pending = CasTestHelpers::seedBlobRow('pending bytes', BlobStatus::Pending);
    $pending->forceFill(['first_seen_at' => now()])->save();

    // The pending row belongs to its commit: a reference-only pin and a re-commit + pin are both refused.
    expect(fn () => CasTestHelpers::pin($pending, 'reference'))->toThrow(BlobUnavailableException::class)
        ->and(fn (): Blob => gcCriteriaIngest('pending bytes', $scope, 'ingest'))->toThrow(BlobUnavailableException::class)
        ->and(CasTestHelpers::pins($pending))->toBe(0)
        ->and($pending->fresh()->status)->toBe(BlobStatus::Pending)
        ->and(gcCriteriaContents($pending))->toBe('pending bytes');

    $gc = app(BlobGarbageCollector::class);
    expect($gc->sweepPending())->toBe(['swept' => 0, 'skipped' => 0])
        ->and($pending->fresh()->status)->toBe(BlobStatus::Pending);

    $this->travel(3)->hours();
    expect($gc->sweepPending())->toBe(['swept' => 1, 'skipped' => 0])
        ->and($pending->fresh()->status)->toBe(BlobStatus::Deleted);
    Storage::disk('local')->assertMissing($pending->storage_key);

    // Nothing was pinned to the vanished bytes; the owner that holds them commits again and pins.
    $again = gcCriteriaIngest('pending bytes', $scope, 'ingest');

    expect((string) $again->getKey())->toBe((string) $pending->getKey())
        ->and($pending->fresh()->status)->toBe(BlobStatus::Committed)
        ->and(CasTestHelpers::pins($pending))->toBe(1)
        ->and(gcCriteriaContents($pending))->toBe('pending bytes');
});

it('sweeps an unpinned pending row only past the orphan grace, not the tombstone grace', function (): void {
    config()->set('vaulter-media.gc.grace_hours', 1);
    config()->set('vaulter-media.gc.orphan_grace_hours', 48);
    $pending = CasTestHelpers::seedBlobRow('crashed staging', BlobStatus::Pending);
    $pending->forceFill(['first_seen_at' => now()->subHours(10)])->save();
    $gc = app(BlobGarbageCollector::class);

    // Ten hours old: past the one-hour tombstone grace, inside the 48-hour orphan grace — not even a candidate.
    expect($gc->sweepPending())->toBe(['swept' => 0, 'skipped' => 0])
        ->and($pending->fresh()->status)->toBe(BlobStatus::Pending);
    Storage::disk('local')->assertExists($pending->storage_key);

    $this->travel(39)->hours();

    expect($gc->sweepPending())->toBe(['swept' => 1, 'skipped' => 0])
        ->and($pending->fresh()->status)->toBe(BlobStatus::Deleted)
        ->and($pending->fresh()->delete_after_at)->toBeNull();
    Storage::disk('local')->assertMissing($pending->storage_key);
});

it('sweeps a tombstone only once its deadline is strictly in the past', function (): void {
    $this->freezeSecond();
    $blob = gcCriteriaCommit('deadline now', BlobScope::tenant('6'));
    $blob->forceFill(['status' => BlobStatus::Tombstoned, 'ref_count' => 0, 'delete_after_at' => now()])->save();
    $gc = app(BlobGarbageCollector::class);

    expect($gc->sweep())->toBe(['swept' => 0, 'skipped' => 0])
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned);
    Storage::disk('local')->assertExists($blob->storage_key);

    $this->travel(1)->seconds();

    expect($gc->sweep())->toBe(['swept' => 1, 'skipped' => 0])
        ->and($blob->fresh()->status)->toBe(BlobStatus::Deleted);
    Storage::disk('local')->assertMissing($blob->storage_key);
});

it('collects only the unpinned copy of bytes that a disabled drive pins in another scope', function (bool $drivePinned): void {
    // The drive is not installed in this suite and is switched off on top: its retained pins still decide.
    config()->set('vaulter.modules.drive', false);
    $driveCopy = gcCriteriaCommit('same bytes, two scopes', BlobScope::storage('9'));
    $tenantCopy = gcCriteriaCommit('same bytes, two scopes', BlobScope::tenant('9'));
    [$kept, $collected] = $drivePinned ? [$driveCopy, $tenantCopy] : [$tenantCopy, $driveCopy];
    app(VaulterDatabase::class)->transaction(fn () => app(BlobPins::class)->pin($kept->getKey(), 'drive.revision_blob', '1:data:0'));
    $kept->forceFill(['ref_count' => 0])->save(); // a stale cache: the pins are re-read under the lock

    expect($driveCopy->sha256)->toBe($tenantCopy->sha256)
        ->and($driveCopy->storage_key)->not->toBe($tenantCopy->storage_key);

    $gc = app(BlobGarbageCollector::class);
    expect($gc->collect(graceHours: 1))->toBe(['tombstoned' => 1, 'swept' => 0, 'skipped' => 1]);
    $this->travel(2)->hours();
    expect($gc->collect(graceHours: 1))->toBe(['tombstoned' => 0, 'swept' => 1, 'skipped' => 1])
        ->and($collected->fresh()->status)->toBe(BlobStatus::Deleted)
        ->and($kept->fresh()->status)->toBe(BlobStatus::Committed)
        ->and(gcCriteriaContents($kept))->toBe('same bytes, two scopes')
        ->and(app(BlobPins::class)->countByOwner())->toBe(['drive.revision_blob' => 1]);
    Storage::disk('local')->assertMissing($collected->storage_key);
})->with(['drive copy pinned' => true, 'tenant copy pinned' => false]);

it('keeps every blob of a module that is not installed through reconcile, both GC phases and the pending sweep', function (): void {
    $owner = 'archive.legacy_copy'; // no package registers this module
    $committed = gcCriteriaCommit('absent owner, committed', BlobScope::tenant('8'));
    app(VaulterDatabase::class)->transaction(fn () => app(BlobPins::class)->pin($committed->getKey(), $owner, 'a'));
    // States the protocol never produces, left by an older writer: the re-check under the lock still sees the pin.
    $tombstoned = CasTestHelpers::seedBlobRow('absent owner, tombstoned', BlobStatus::Tombstoned);
    $tombstoned->forceFill(['delete_after_at' => now()->subDay()])->save();
    CasTestHelpers::seedPin($tombstoned, 'b', $owner);
    $pending = CasTestHelpers::seedBlobRow('absent owner, pending', BlobStatus::Pending);
    $pending->forceFill(['first_seen_at' => now()->subDays(3)])->save();
    CasTestHelpers::seedPin($pending, 'c', $owner);

    app(BlobReconciler::class)->reconcile();
    $committed->fresh()->forceFill(['ref_count' => 0])->save();
    $gc = app(BlobGarbageCollector::class);
    $gc->collect(graceHours: 1);
    $this->travel(2)->days();
    $gc->collect(graceHours: 1);
    $gc->sweepPending(graceHours: 1);

    expect($committed->fresh()->status)->toBe(BlobStatus::Committed)
        ->and($tombstoned->fresh()->status)->toBe(BlobStatus::Tombstoned)
        ->and($pending->fresh()->status)->toBe(BlobStatus::Pending)
        ->and(app(BlobPins::class)->countByOwner())->toBe([$owner => 3]);
    foreach ([$committed, $tombstoned, $pending] as $blob) {
        Storage::disk('local')->assertExists($blob->storage_key);
    }
});
