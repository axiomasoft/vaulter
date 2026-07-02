<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * vaulter:purge-trash (D17 / §2.10): nodes trashed past the retention window are
 * force-deleted (their subtrees soft-deleted, blob edges detached so the bytes
 * become GC-eligible); a node still inside retention is left untouched.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter.trash.retention_days', 30);
});

it('purges a node trashed past the retention window', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'old');

    // Trashed 40 days ago — past the 30-day retention.
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(40)])->save();

    $this->artisan('vaulter:purge-trash')->assertSuccessful();

    // The node is now permanently soft-deleted (deleted_at) — gone from the live set.
    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse()
        ->and(Node::withTrashed()->whereKey($node->getKey())->first()->deleted_at)->not->toBeNull();
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

it('honours the --days override', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'tenday');
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(10)])->save();

    // With a 7-day override, a 10-day-old trashed node IS purged.
    $this->artisan('vaulter:purge-trash', ['--days' => '7'])->assertSuccessful();

    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse();
});
