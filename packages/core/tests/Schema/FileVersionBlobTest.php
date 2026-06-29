<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Vaulter\Enums\NodeKind;
use Vaulter\Models\Blob;
use Vaulter\Models\File;
use Vaulter\Models\FileVersion;
use Vaulter\Models\Node;
use Vaulter\Models\Storage;

/**
 * The file ↔ version ↔ blob chain and the v_file_version_blobs edge (the GC
 * truth, §2.4): relations resolve through the swappable model map.
 */
beforeEach(function (): void {
    $storage = Storage::query()->create([
        'owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk',
    ]);

    $node = Node::query()->create([
        'storage_id' => $storage->getKey(), 'kind' => NodeKind::File, 'name' => 'a.pdf',
    ]);

    $this->file = File::query()->create([
        'node_id' => $node->getKey(),
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
    ]);

    $this->blob = Blob::query()->create([
        'sha256' => str_repeat('b', 64),
        'size_bytes' => 100,
        'storage_key' => 'global/bb/bb/'.str_repeat('b', 64),
        'status' => 'committed',
    ]);

    $this->version = FileVersion::query()->create([
        'file_id' => $this->file->getKey(),
        'version_no' => 1,
        'blob_id' => $this->blob->getKey(),
        'content_sha256' => str_repeat('b', 64),
        'size_bytes' => 100,
        'original_name' => 'a.pdf',
        'status' => 'committed',
    ]);

    $this->file->update(['current_version_id' => $this->version->getKey()]);
});

it('file -> versions -> blob связаны', function (): void {
    expect($this->file->versions()->count())->toBe(1)
        ->and($this->file->currentVersion->getKey())->toBe($this->version->getKey())
        ->and($this->version->blob->getKey())->toBe($this->blob->getKey());
});

it('ребро version<->blob — это GC-истина через pivot', function (): void {
    $this->version->blobs()->attach($this->blob->getKey(), [
        'role' => 'data',
        'ordinal' => 0,
    ]);

    expect($this->version->blobs()->count())->toBe(1)
        ->and($this->blob->versions()->count())->toBe(1)
        ->and($this->version->blobs()->first()->pivot->role)->toBe('data');
});

it('version_no монотонно уникален на файл', function (): void {
    expect(fn () => FileVersion::query()->create([
        'file_id' => $this->file->getKey(),
        'version_no' => 1,
        'content_sha256' => str_repeat('c', 64),
        'size_bytes' => 1,
        'original_name' => 'a.pdf',
    ]))->toThrow(QueryException::class);
});
