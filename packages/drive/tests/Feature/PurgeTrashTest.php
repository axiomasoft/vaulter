<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * vaulter:purge-trash (D17 / §2.10 / F-P1.12-02): nodes trashed past the retention
 * window are PHYSICALLY purged — the stage-2 force-delete detaches version↔blob
 * edges (the bytes become GC-eligible), then the subtree's own rows are hard-deleted
 * (not just soft-deleted, F-P1.12-02) so FK cascade removes dependent rows too. A
 * node still inside retention is left untouched.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-drive.trash.retention_days', 30);
});

it('physically purges a node trashed past the retention window, F-P1.12-02', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'old');

    // Trashed 40 days ago — past the 30-day retention.
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(40)])->save();

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    // The node is now PHYSICALLY gone (F-P1.12-02: purge means purge; nodes have no
    // soft-delete tombstone a forensic query could still see).
    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse();
});

it('leaves a node still inside the retention window untouched', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'recent');

    // Trashed 5 days ago — well inside the 30-day retention.
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(5)])->save();

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    expect(Node::query()->whereKey($node->getKey())->exists())->toBeTrue();
});

it('frees the blob edges of a purged file subtree (GC-eligible)', function (): void {
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');
    $fileNode = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('purge me'), 'p.txt'));

    // The blob is referenced (one live edge) while the file lives.
    $blob = Blob::query()->firstOrFail();
    expect($blob->versions()->count())->toBe(1);

    $fileNode->refresh()->forceFill(['trashed_at' => Carbon::now()->subDays(40)])->save();

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    // The edge is detached — the blob is now unreferenced (GC-eligible).
    expect($blob->refresh()->versions()->count())->toBe(0);
});

it('cascades the physical purge to file/version rows on PostgreSQL, F-P1.12-02', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('FK cascadeOnDelete is not enforced on the default SQLite test connection.');
    }

    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');
    $fileNode = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('purge me too'), 'p2.txt'));
    $fileId = $fileNode->file()->first()->getKey();

    $fileNode->refresh()->forceFill(['trashed_at' => Carbon::now()->subDays(40)])->save();

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    // The node AND every row that FK-cascades off it are physically gone — not
    // just the version↔blob edge (already covered by the GC-eligibility test
    // above), the v_files/v_file_revisions rows themselves.
    expect(Node::query()->whereKey($fileNode->getKey())->exists())->toBeFalse()
        ->and(DB::table('v_files')->where('id', $fileId)->exists())->toBeFalse()
        ->and(DB::table('v_file_revisions')->where('file_id', $fileId)->exists())->toBeFalse();
});

it('does not physically purge a node restored between candidate materialisation and the purge transaction, TOCTOU guard (P2.2 review fix)', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('raced');

    $drive->nodes()->trash($node);
    // Backdate past the retention window so it becomes a purge candidate.
    $node->refresh()->forceFill(['trashed_at' => Carbon::now()->subDays(40)])->save();

    $targetId = $node->getKey();
    $restoredOnce = false;

    // Fires when the candidate row is hydrated by PurgeTrashCommand's initial
    // `trashedBefore()->get()` — i.e. strictly BEFORE the per-node purge
    // transaction opens. Simulates the owning user calling `restore` in the
    // TOCTOU window between candidate materialisation and the transaction that
    // physically (and irreversibly, ADR-0016) deletes the subtree.
    Node::retrieved(function (Node $model) use ($drive, $targetId, &$restoredOnce): void {
        if ($restoredOnce || $model->getKey() !== $targetId) {
            return;
        }

        $restoredOnce = true;
        $drive->trash()->restore(Node::query()->whereKey($targetId)->firstOrFail());
    });

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    // The re-check + row-lock inside the purge transaction sees the CURRENT
    // (restored) state and aborts the purge for this subtree — the node
    // survives, live and un-trashed, instead of being physically deleted out
    // from under the user who just restored it.
    expect(Node::query()->whereKey($targetId)->exists())->toBeTrue()
        ->and($node->refresh()->trashed_at)->toBeNull();
});

it('honours the --days override', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'tenday');
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(10)])->save();

    // With a 7-day override, a 10-day-old trashed node IS purged.
    $this->artisan('vaulter:purge-trash', ['--days' => '7'])->assertSuccessful();

    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse();
});
