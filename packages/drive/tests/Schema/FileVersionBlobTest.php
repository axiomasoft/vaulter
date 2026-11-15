<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\RevisionBlobLinks;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Tests\Schema\ForceCreates;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Models\Cas\Blob;

/**
 * The file ↔ version ↔ blob chain and the v_file_revision_blobs edge with its
 * media pin: relations resolve through the swappable model map.
 */
beforeEach(function (): void {
    $storage = ForceCreates::make(Drive::class, [
        'owner_type' => 'team', 'owner_id' => 1, 'name' => 'Disk',
    ]);

    $node = ForceCreates::make(Node::class, [
        'drive_id' => $storage->getKey(), 'kind' => NodeKind::File, 'name' => 'a.pdf',
    ]);

    $this->file = ForceCreates::make(File::class, [
        'node_id' => $node->getKey(),
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
    ]);

    $this->blob = ForceCreates::make(Blob::class, [
        'scope_key' => 'global',
        'sha256' => str_repeat('b', 64),
        'size_bytes' => 100,
        'storage_key' => 'global/bb/bb/'.str_repeat('b', 64),
        'status' => 'committed',
    ]);

    $this->version = ForceCreates::make(FileRevision::class, [
        'file_id' => $this->file->getKey(),
        'number' => 1,
        'blob_id' => $this->blob->getKey(),
        'sha256' => str_repeat('b', 64),
        'size_bytes' => 100,
        'original_name' => 'a.pdf',
        'status' => 'committed',
    ]);

    $this->file->forceFill(['current_revision_id' => $this->version->getKey()])->save();
});

it('file -> versions -> blob связаны', function (): void {
    expect($this->file->versions()->count())->toBe(1)
        ->and($this->file->currentRevision->getKey())->toBe($this->version->getKey())
        ->and($this->version->blob->getKey())->toBe($this->blob->getKey());
});

it('ребро version<->blob — это GC-истина через pivot', function (): void {
    // FileRevisionBlob is fully guarded — the production write
    // path is the drive's RevisionBlobLinks over the query-builder repository, not
    // Eloquent's own relation attach(); it writes the edge and its media pin
    // inside one transaction.
    app(VaulterDatabase::class)->transaction(fn () => app(RevisionBlobLinks::class)->attach($this->version, $this->blob, [
        'role' => 'data',
        'ordinal' => 0,
    ]));

    expect($this->version->blobs()->count())->toBe(1)
        ->and(FileRevisionBlob::query()->where('blob_id', $this->blob->getKey())->count())->toBe(1)
        ->and($this->version->blobs()->first()->pivot->role)->toBe('data')
        ->and(app(BlobPins::class)->countByOwner())->toBe([RevisionBlobLinks::OWNER_TYPE => 1]);
});

it('number монотонно уникален на файл', function (): void {
    // SAVEPOINT the violating insert: on PostgreSQL a UNIQUE violation aborts the whole
    // enclosing RefreshDatabase transaction, poisoning the connection for the rest of the
    // suite; the nested DB::transaction rolls back only the failed insert. SQLite tolerates
    // either form (see BlobDedupScopeTest for the reference rationale).
    expect(fn () => DB::transaction(fn (): Model => ForceCreates::make(FileRevision::class, [
        'file_id' => $this->file->getKey(),
        'number' => 1,
        'sha256' => str_repeat('c', 64),
        'size_bytes' => 1,
        'original_name' => 'a.pdf',
    ])))->toThrow(QueryException::class);
});
