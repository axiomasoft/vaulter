<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage as StorageFacade;
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
    config()->set('vaulter.trash.retention_days', 30);
});

it('physically purges a node trashed past the retention window, F-P1.12-02', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'old');

    // Trashed 40 days ago — past the 30-day retention.
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(40)])->save();

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    // The node is now PHYSICALLY gone — not merely soft-deleted (F-P1.12-02: purge
    // means purge, not another soft-delete tombstone that a forensic
    // withTrashed() query can still see).
    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse()
        ->and(Node::withTrashed()->whereKey($node->getKey())->exists())->toBeFalse();
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
    $drive = Vaulter::for(DriveTestHelpers::host());
    $fileNode = $drive->upload(DriveTestHelpers::streamFor('purge me'), originalName: 'p.txt');

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

    $drive = Vaulter::for(DriveTestHelpers::host());
    $fileNode = $drive->upload(DriveTestHelpers::streamFor('purge me too'), originalName: 'p2.txt');
    $fileId = $fileNode->file()->first()->getKey();

    $fileNode->refresh()->forceFill(['trashed_at' => Carbon::now()->subDays(40)])->save();

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    // The node AND every row that FK-cascades off it are physically gone — not
    // just the version↔blob edge (already covered by the GC-eligibility test
    // above), the v_files/v_file_versions rows themselves.
    expect(Node::withTrashed()->whereKey($fileNode->getKey())->exists())->toBeFalse()
        ->and(DB::table('v_files')->where('id', $fileId)->exists())->toBeFalse()
        ->and(DB::table('v_file_versions')->where('file_id', $fileId)->exists())->toBeFalse();
});

it('honours the --days override', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'tenday');
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(10)])->save();

    // With a 7-day override, a 10-day-old trashed node IS purged.
    $this->artisan('vaulter:purge-trash', ['--days' => '7'])->assertSuccessful();

    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse();
});
