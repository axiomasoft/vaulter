<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Exceptions\Drive\QuotaExceededException as CoreQuotaExceededException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
});

it('reserves from the database value with unlimited and exact-boundary quotas', function (?int $quota): void {
    $storage = DriveTestHelpers::drive()->model();
    $storage->forceFill(['quota_bytes' => $quota, 'used_bytes' => 2])->save();
    $stale = $storage->fresh();
    $storage->forceFill(['used_bytes' => 4])->save();

    app(VaulterDatabase::class)->transaction(fn () => app(DriveStoreRepository::class)->reserveBytes($stale, 6));

    expect($storage->refresh()->used_bytes)->toBe(10);
})->with([null, 10]);

it('rejects one byte over the quota without changing used_bytes', function (): void {
    $storage = DriveTestHelpers::drive()->model();
    $storage->forceFill(['quota_bytes' => 10, 'used_bytes' => 10])->save();

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => app(DriveStoreRepository::class)->reserveBytes($storage, 1)))
        ->toThrow(CoreQuotaExceededException::class);
    expect($storage->refresh()->used_bytes)->toBe(10);
});

it('releases bytes down to zero and accepts a zero reservation', function (): void {
    $storage = DriveTestHelpers::drive()->model();
    $storage->forceFill(['quota_bytes' => 2, 'used_bytes' => 2])->save();
    $repo = app(DriveStoreRepository::class);

    app(VaulterDatabase::class)->transaction(function () use ($repo, $storage): void {
        $repo->reserveBytes($storage, 0);
        $repo->reserveBytes($storage, -5);
    });
    expect($storage->refresh()->used_bytes)->toBe(0);
});

it('rolls back an entire subtree copy when its summed heads exceed quota', function (): void {
    $drive = DriveTestHelpers::drive();
    $folder = $drive->createFolder('source');
    $drive->upload(DriveTestHelpers::streamFor('aaaa'), into: $folder, originalName: 'a.txt');
    $drive->upload(DriveTestHelpers::streamFor('bbbbbb'), into: $folder, originalName: 'b.txt');
    $drive->model()->forceFill(['quota_bytes' => 19])->save();
    $counts = [Node::query()->count(), File::query()->count(), FileRevision::query()->count(), FileRevisionBlob::query()->count()];

    expect(fn (): Node => $drive->copy($folder, name: 'copy'))->toThrow(QuotaExceededException::class);

    expect([Node::query()->count(), File::query()->count(), FileRevision::query()->count(), FileRevisionBlob::query()->count()])->toBe($counts)
        ->and(Node::query()->where('name', 'copy')->exists())->toBeFalse()
        ->and($drive->model()->refresh()->used_bytes)->toBe(10)
        ->and(Blob::query()->count())->toBe(2);
});

it('uses the locked file head despite stale node size and stale quota snapshots', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('aaaa'), originalName: 'a.txt');
    $stale = $node->fresh();
    $drive->uploadVersion($node, DriveTestHelpers::streamFor('abcdefghij'), originalName: 'a.txt');
    $drive->model()->forceFill(['quota_bytes' => 10])->save();
    // The stale node says 4 bytes; the locked file head says 10. Replacing it
    // with 8 bytes releases 2, rather than reserving 4 and rejecting the upload.
    $drive->uploadVersion($stale, DriveTestHelpers::streamFor('abcdefgh'), originalName: 'a.txt');
    expect($drive->model()->refresh()->used_bytes)->toBe(8)
        ->and($stale->fresh()->file->revision_count)->toBe(3);
});

it('copies a stale file from its current head and reserves that exact size', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->upload(DriveTestHelpers::streamFor('aaaa'), originalName: 'a.txt');
    $stale = $node->fresh();
    $drive->uploadVersion($node, DriveTestHelpers::streamFor('abcdefghij'), originalName: 'a.txt');
    $copy = $drive->copy($stale, name: 'copy.txt');
    expect($copy->size_bytes)->toBe(10)
        ->and($copy->file->currentVersion->size_bytes)->toBe(10)
        ->and($drive->model()->refresh()->used_bytes)->toBe(20);
});
