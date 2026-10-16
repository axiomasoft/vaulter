<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

it('forceDelete отцепляет рёбра блоба → блоб становится GC-кандидатом', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('payload bytes'), 'f.bin'));

    expect(FileRevisionBlob::query()->count())->toBe(1);

    $drive->nodes()->trash($node);

    $drive->trash()->purge($node);

    // The edge is detached — the blob now has zero live edges (GC-eligible via M1b),
    // but its bytes are NOT deleted here.
    expect(FileRevisionBlob::query()->count())->toBe(0)
        ->and(Blob::query()->count())->toBe(1);

    $blob = Blob::query()->first();
    expect($blob->versions()->count())->toBe(0);
});

it('forceDelete уменьшает used_bytes на освобождённый размер', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('1234567890'), 'f.bin')); // 10
    expect($drive->model()->refresh()->used_bytes)->toBe(10);

    $drive->nodes()->trash($node);

    $drive->trash()->purge($node);

    expect($drive->model()->refresh()->used_bytes)->toBe(0);
});

it('purge физически удаляет всё поддерево', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->folders()->create('Project');
    $sub = $drive->folders()->create('Sub', $folder);
    $file = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('aa'), 'a.bin'), new UploadOptions(parent: $sub));

    $drive->nodes()->trash($folder);

    $drive->trash()->purge($folder);

    // Purge physically removes the entire subtree; only the drive root survives.
    expect(array_map('strval', Node::query()->pluck('id')->all()))->toBe([$drive->root()->id])
        ->and(Node::query()->whereIn('id', [$folder->id, $sub->id, $file->id])->exists())->toBeFalse();
});

it('forceDelete не трогает байты на диске (GC отдельно)', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('keep these bytes'), 'f.bin'));
    $blob = Blob::query()->first();

    $drive->nodes()->trash($node);

    $drive->trash()->purge($node);

    // The physical object survives — only the reference dropped (M1b GC reclaims it).
    Storage::disk('local')->assertExists($blob->storage_key);
});
