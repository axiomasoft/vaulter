<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Cas\BlobReconciler;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\FailingDeleteContentStore;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/*
 * The collector and the reconciler read the pins and nothing else (D23, P3/P6): a pin of any owner — even of a module
 * that is disabled or not installed — keeps its blob, and ref_count is only their cache.
 */

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

function pinGcBlob(string $bytes): Blob
{
    return app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), BlobScope::tenant('3'));
}

it('rebuilds ref_count from the pins of every owner type', function (): void {
    $blob = pinGcBlob('counted');
    CasTestHelpers::pin($blob, 'a');
    CasTestHelpers::pin($blob, 'b', 'drive.revision_blob');
    CasTestHelpers::pin($blob, 'c', 'documents.attachment');

    expect(app(BlobReconciler::class)->reconcile()['changed'])->toBe(1)
        ->and($blob->fresh()->ref_count)->toBe(3);

    CasTestHelpers::unpin($blob, 'b', 'drive.revision_blob');
    app(BlobReconciler::class)->reconcile();

    expect($blob->fresh()->ref_count)->toBe(2);
});

it('reconciles more blobs than one batch holds', function (): void {
    $blobs = CasTestHelpers::seedBlobRows(1003, 'reconcile batch ', BlobStatus::Committed, ['ref_count' => 5]);
    CasTestHelpers::seedPin($blobs[1001]);

    $report = app(BlobReconciler::class)->reconcile();

    expect($report['reconciled'])->toBe(1003)
        ->and($report['changed'])->toBe(1003)
        ->and($blobs[1001]->fresh()->ref_count)->toBe(1)
        ->and($blobs[0]->fresh()->ref_count)->toBe(0);
});

it('tombstones a blob only after its last pin is gone', function (): void {
    $blob = pinGcBlob('two owners');
    CasTestHelpers::pin($blob, 'a');
    CasTestHelpers::pin($blob, 'b', 'other.owner');
    $gc = app(BlobGarbageCollector::class);

    CasTestHelpers::unpin($blob, 'a');
    app(BlobReconciler::class)->reconcile();
    $blob->fresh()->forceFill(['ref_count' => 0])->save(); // a stale cache must not decide: the pins are re-read under the lock

    expect($gc->mark(graceHours: 24))->toBe(['tombstoned' => 0, 'skipped' => 1])
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);

    CasTestHelpers::unpin($blob, 'b', 'other.owner');
    app(BlobReconciler::class)->reconcile();

    expect($gc->mark(graceHours: 24)['tombstoned'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Tombstoned);
});

it('keeps the pins and blobs of a disabled or absent owner module (P6)', function (): void {
    // The drive is not even installed in this suite, and switched off on top: its pins stay authoritative.
    config()->set('vaulter.modules.drive', false);
    $marked = pinGcBlob('drive owned');
    $swept = pinGcBlob('drive owned, tombstoned');
    CasTestHelpers::pin($marked, '1:data:0', 'drive.revision_blob');
    CasTestHelpers::pin($swept, '2:data:0', 'drive.revision_blob');
    $marked->forceFill(['ref_count' => 0])->save();
    $swept->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subDay(), 'ref_count' => 0])->save();

    $result = app(BlobGarbageCollector::class)->collect(graceHours: 1);

    expect($result)->toBe(['tombstoned' => 0, 'swept' => 0, 'skipped' => 2])
        ->and($marked->fresh()->status)->toBe(BlobStatus::Committed)
        ->and($swept->fresh()->status)->toBe(BlobStatus::Tombstoned)
        ->and(app(BlobPins::class)->countByOwner())->toBe(['drive.revision_blob' => 2]);
    Storage::disk('local')->assertExists($marked->storage_key);
    Storage::disk('local')->assertExists($swept->storage_key);
});

it('never lets a pin outlive the bytes: a pin after the sweep is refused and a re-commit restores them', function (): void {
    $blob = pinGcBlob('swept then wanted');
    $blob->forceFill(['ref_count' => 0])->save();
    $gc = app(BlobGarbageCollector::class);
    $gc->mark(graceHours: 1);
    $this->travel(2)->hours();
    expect($gc->sweep()['swept'])->toBe(1)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Deleted);
    Storage::disk('local')->assertMissing($blob->storage_key);

    expect(fn () => CasTestHelpers::pin($blob, 'late'))->toThrow(BlobUnavailableException::class);

    app(VaulterDatabase::class)->transaction(function (): void {
        $again = pinGcBlob('swept then wanted');
        app(BlobPins::class)->pin($again, CasTestHelpers::OWNER, 'late');
    });

    expect($blob->fresh()->status)->toBe(BlobStatus::Committed)
        ->and(CasTestHelpers::pins($blob))->toBe(1);
    Storage::disk('local')->assertExists($blob->storage_key);
    expect($gc->collect(graceHours: 1))->toBe(['tombstoned' => 0, 'swept' => 0, 'skipped' => 1]);
});

it('refuses a pin to the tombstone a rolled-back sweep left without bytes, and the re-commit heals it', function (): void {
    // The sweep deletes the object before it records the status; a failure after the delete rolls the row back to
    // tombstoned while the object is gone — the hazard the pin protocol closes (architecture#operation-chains).
    $blob = pinGcBlob('rolled back sweep');
    $blob->forceFill(['status' => BlobStatus::Tombstoned, 'delete_after_at' => now()->subMinute()])->save();
    $content = app(ContentStore::class);
    app()->instance(ContentStore::class, new FailingDeleteContentStore($content));

    expect(fn () => app(BlobGarbageCollector::class)->sweep())->toThrow(RuntimeException::class, 'after the delete');
    app()->instance(ContentStore::class, $content);

    expect($blob->fresh()->status)->toBe(BlobStatus::Tombstoned)
        ->and($content->exists($blob->storage_key, $blob->disk))->toBeFalse()
        ->and(fn () => CasTestHelpers::pin($blob, 'owner'))->toThrow(BlobUnavailableException::class)
        ->and(CasTestHelpers::pins($blob))->toBe(0);

    app(VaulterDatabase::class)->transaction(function (): void {
        app(BlobPins::class)->pin(pinGcBlob('rolled back sweep'), CasTestHelpers::OWNER, 'owner');
    });

    expect($blob->fresh()->status)->toBe(BlobStatus::Committed)
        ->and($content->exists($blob->storage_key, $blob->disk))->toBeTrue()
        ->and(CasTestHelpers::pins($blob))->toBe(1);
});

it('re-reads the pins under the lock in the sweep of a pending row', function (): void {
    $pending = CasTestHelpers::seedBlobRow('pending pinned', BlobStatus::Pending);
    $pending->forceFill(['first_seen_at' => now()->subDays(2)])->save();
    // Seeded: through the protocol a pin of a pending row is refused (D23).
    CasTestHelpers::seedPin($pending, 'owner');

    expect(app(BlobGarbageCollector::class)->sweepPending(graceHours: 1))->toBe(['swept' => 0, 'skipped' => 1])
        ->and($pending->fresh()->status)->toBe(BlobStatus::Pending);
    Storage::disk('local')->assertExists($pending->storage_key);
});
