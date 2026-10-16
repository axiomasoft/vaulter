<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Exceptions\Quota\QuotaExceededException;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Exceptions\Drive\QuotaExceededException as CoreQuotaExceededException;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\DriveStoreRepository;

beforeEach(fn () => DriveTestHelpers::insideOperation());

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
    $folder = $drive->folders()->create('source');
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('aaaa'), 'a.txt'), new UploadOptions(parent: $folder));
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bbbbbb'), 'b.txt'), new UploadOptions(parent: $folder));
    $drive->model()->forceFill(['quota_bytes' => 19])->save();
    $counts = [Node::query()->count(), File::query()->count(), FileRevision::query()->count(), FileRevisionBlob::query()->count()];

    expect(fn (): NodeSnapshot => $drive->nodes()->copy($folder, destination: null, options: new CopyOptions(name: 'copy')))->toThrow(QuotaExceededException::class);

    expect([Node::query()->count(), File::query()->count(), FileRevision::query()->count(), FileRevisionBlob::query()->count()])->toBe($counts)
        ->and(Node::query()->where('name', 'copy')->exists())->toBeFalse()
        ->and($drive->model()->refresh()->used_bytes)->toBe(10)
        ->and(Blob::query()->count())->toBe(2);
});

it('reserves a revision against the database quota value despite a stale node and drive snapshot', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('aaaa'), 'a.txt'));
    $stale = DriveTestHelpers::model($node);
    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('abcdefghij'), 'a.txt'));
    // 14 retained bytes; the stale node still says 4.
    $drive->model()->forceFill(['quota_bytes' => 21])->save();
    expect(fn () => $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('abcdefgh'), 'a.txt')))
        ->toThrow(QuotaExceededException::class);
    $drive->model()->forceFill(['quota_bytes' => 22])->save();
    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('abcdefgh'), 'a.txt'));
    expect($drive->model()->refresh()->used_bytes)->toBe(22)
        ->and($stale->fresh()->file->revision_count)->toBe(3);
});

it('copies a stale file from its current head and reserves that exact size', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('aaaa'), 'a.txt'));
    $stale = DriveTestHelpers::model($node);
    $drive->files()->addRevision($node, UploadSource::fromStream(DriveTestHelpers::streamFor('abcdefghij'), 'a.txt'));
    $copy = $drive->nodes()->copy($node, destination: null, options: new CopyOptions(name: 'copy.txt'));
    expect($copy->sizeBytes)->toBe(10)
        ->and($copy->file->sizeBytes)->toBe(10)
        ->and($drive->model()->refresh()->used_bytes)->toBe(4 + 10 + 10);
});
