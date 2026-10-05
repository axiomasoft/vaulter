<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.dedup.scope', 'tenant');
});

it('forceDelete отцепляет рёбра блоба → блоб становится GC-кандидатом', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('payload bytes'), originalName: 'f.bin');

    expect(FileRevisionBlob::query()->count())->toBe(1);

    $drive->trash($node);

    $drive->forceDelete($node);

    // The edge is detached — the blob now has zero live edges (GC-eligible via M1b),
    // but its bytes are NOT deleted here.
    expect(FileRevisionBlob::query()->count())->toBe(0)
        ->and(Blob::query()->count())->toBe(1);

    $blob = Blob::query()->first();
    expect($blob->versions()->count())->toBe(0);
});

it('forceDelete уменьшает used_bytes на освобождённый размер', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('1234567890'), originalName: 'f.bin'); // 10
    expect($drive->model()->refresh()->used_bytes)->toBe(10);

    $drive->trash($node);

    $drive->forceDelete($node);

    expect($drive->model()->refresh()->used_bytes)->toBe(0);
});

it('forceDelete тумбстонит всё поддерево (deleted_at)', function (): void {
    $drive = DriveTestHelpers::drive();

    $folder = $drive->createFolder('Project');
    $sub = $drive->createFolder('Sub', $folder);
    $drive->upload(DriveTestHelpers::streamFor('aa'), into: $sub, originalName: 'a.bin');

    $drive->trash($folder);

    $drive->forceDelete($folder);

    // Whole subtree soft-deleted; only the storage root remains live.
    expect(Node::query()->whereNull('deleted_at')->count())->toBe(1)
        ->and(Node::withTrashed()->count())->toBeGreaterThanOrEqual(4);
});

it('forceDelete не трогает байты на диске (GC отдельно)', function (): void {
    $drive = DriveTestHelpers::drive();

    $node = $drive->upload(DriveTestHelpers::streamFor('keep these bytes'), originalName: 'f.bin');
    $blob = Blob::query()->first();

    $drive->trash($node);

    $drive->forceDelete($node);

    // The physical object survives — only the reference dropped (M1b GC reclaims it).
    Storage::disk('local')->assertExists($blob->storage_key);
});
