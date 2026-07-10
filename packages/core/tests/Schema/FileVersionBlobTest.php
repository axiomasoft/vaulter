<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\FileVersionBlobStoreRepository;
use Vaulter\Tests\Schema\ForceCreates;

/**
 * The file ↔ version ↔ blob chain and the v_file_version_blobs edge (the GC
 * truth, §2.4): relations resolve through the swappable model map.
 */
beforeEach(function (): void {
    $storage = ForceCreates::make(Storage::class, [
        'owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk',
    ]);

    $node = ForceCreates::make(Node::class, [
        'storage_id' => $storage->getKey(), 'kind' => NodeKind::File, 'name' => 'a.pdf',
    ]);

    $this->file = ForceCreates::make(File::class, [
        'node_id' => $node->getKey(),
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
    ]);

    $this->blob = ForceCreates::make(Blob::class, [
        'sha256' => str_repeat('b', 64),
        'size_bytes' => 100,
        'storage_key' => 'global/bb/bb/'.str_repeat('b', 64),
        'status' => 'committed',
    ]);

    $this->version = ForceCreates::make(FileVersion::class, [
        'file_id' => $this->file->getKey(),
        'version_no' => 1,
        'blob_id' => $this->blob->getKey(),
        'content_sha256' => str_repeat('b', 64),
        'size_bytes' => 100,
        'original_name' => 'a.pdf',
        'status' => 'committed',
    ]);

    $this->file->forceFill(['current_version_id' => $this->version->getKey()])->save();
});

it('file -> versions -> blob связаны', function (): void {
    expect($this->file->versions()->count())->toBe(1)
        ->and($this->file->currentVersion->getKey())->toBe($this->version->getKey())
        ->and($this->version->blob->getKey())->toBe($this->blob->getKey());
});

it('ребро version<->blob — это GC-истина через pivot', function (): void {
    // FileVersionBlob is fully guarded (P1.4/F-P1.8-02) — the production write
    // path is the query-builder repository, not Eloquent's own relation
    // attach() (which routes pivot attributes through fill()/mass-assignment
    // and would now throw MassAssignmentException).
    app(FileVersionBlobStoreRepository::class)->attach($this->version, $this->blob, [
        'role' => 'data',
        'ordinal' => 0,
    ]);

    expect($this->version->blobs()->count())->toBe(1)
        ->and($this->blob->versions()->count())->toBe(1)
        ->and($this->version->blobs()->first()->pivot->role)->toBe('data');
});

it('version_no монотонно уникален на файл', function (): void {
    // SAVEPOINT the violating insert: on PostgreSQL a UNIQUE violation aborts the whole
    // enclosing RefreshDatabase transaction, poisoning the connection for the rest of the
    // suite; the nested DB::transaction rolls back only the failed insert. SQLite tolerates
    // either form (see BlobDedupScopeTest for the reference rationale).
    expect(fn () => DB::transaction(fn () => ForceCreates::make(FileVersion::class, [
        'file_id' => $this->file->getKey(),
        'version_no' => 1,
        'content_sha256' => str_repeat('c', 64),
        'size_bytes' => 1,
        'original_name' => 'a.pdf',
    ])))->toThrow(QueryException::class);
});
