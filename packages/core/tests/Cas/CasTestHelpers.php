<?php

declare(strict_types=1);

namespace Vaulter\Tests\Cas;

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Cas\Sha256ContentStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileVersion;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;

/**
 * Shared builders for the CAS slice tests (M1b): a real Storage→Node→File→Version
 * chain so attach()/detach() can hang real edges off a committed blob, plus a
 * helper to turn a string into a stream the ContentStore can stage.
 */
final class CasTestHelpers
{
    /**
     * Build a committed FileVersion (with its File + Node + Storage) to attach a
     * blob edge to.
     */
    public static function makeVersion(int $versionNo = 1, string $name = 'a.bin'): FileVersion
    {
        // One storage per host owner (the M1c owner-unique index): mint a fresh
        // owner_id per call so repeated makeVersion()s in one test don't collide.
        static $owner = 0;
        $owner++;

        $storage = Storage::query()->create([
            'owner_type' => 'team',
            'owner_id' => $owner,
            'name' => 'Disk',
        ]);

        $node = Node::query()->create([
            'storage_id' => $storage->getKey(),
            'kind' => NodeKind::File,
            'name' => $name,
        ]);

        $file = File::query()->create([
            'node_id' => $node->getKey(),
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 0,
        ]);

        return FileVersion::query()->create([
            'file_id' => $file->getKey(),
            'version_no' => $versionNo,
            'content_sha256' => str_repeat('0', 64),
            'size_bytes' => 0,
            'original_name' => $name,
            'status' => 'committed',
        ]);
    }

    /**
     * An in-memory read stream over the given bytes.
     *
     * @return resource
     */
    public static function streamFor(string $bytes)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /**
     * Seed a GENUINE orphan object on the disk: the bytes are written at their REAL
     * canonical CAS key (keyFor(scope, sha256(bytes))) so the round-trip guard
     * accepts them, with NO blob row, and aged $ageHours into the past via touch()
     * so the orphan grace can be exercised. Returns the storage key.
     *
     * Mirrors how the producer (BlobStore::commit) would lay the object down, minus
     * the row — exactly the "promoted bytes then rolled the row back / leaked bytes"
     * hazard the orphan walk hunts (H.3). The disk must already be a Storage::fake.
     */
    public static function seedOrphan(string $bytes, string $scope = 'tnull', int $ageHours = 48, string $disk = 'local'): string
    {
        $key = self::contentStore()->keyFor(scope: $scope, sha256: hash('sha256', $bytes));

        StorageFacade::disk($disk)->put($key, $bytes);
        self::ageObject(key: $key, ageHours: $ageHours, disk: $disk);

        return $key;
    }

    /**
     * Write a canonical object at $key and back-date its mtime by $ageHours via
     * touch() on the faked disk's real path — the age lever the orphan grace reads
     * through ContentStore::lastModified(). A non-positive $ageHours leaves the mtime
     * at now (a FRESH object).
     */
    public static function ageObject(string $key, int $ageHours, string $disk = 'local'): void
    {
        if ($ageHours <= 0) {
            return;
        }

        touch(StorageFacade::disk($disk)->path($key), now()->subHours($ageHours)->getTimestamp());
    }

    /**
     * Create a blob row pinned to an EXACT storage_key + disk and forced to a given
     * lifecycle status, with the matching bytes on disk. Used to prove that an object
     * owned by a row of ANY status is skipped (never an orphan), and that ownership is
     * disk-agnostic (the row's $disk may differ from the scanned disk).
     *
     * The bytes are written on $bytesDisk (defaults to the row's $disk) so the object
     * physically exists for the walk to find; pass a different $bytesDisk to model the
     * cross-disk-ownership regression (row on one disk, bytes on another).
     */
    public static function seedBlobRow(
        string $bytes,
        BlobStatus $status,
        string $disk = 'local',
        ?string $bytesDisk = null,
        string $scope = 'tnull',
    ): Blob {
        $sha256 = hash('sha256', $bytes);
        $storageKey = self::contentStore()->keyFor(scope: $scope, sha256: $sha256);

        StorageFacade::disk($bytesDisk ?? $disk)->put($storageKey, $bytes);

        /** @var Blob $blob */
        $blob = Blob::query()->create([
            'tenant_id' => null,
            'storage_id' => null,
            'hash_algo' => 'sha256',
            'sha256' => $sha256,
            'size_bytes' => strlen($bytes),
            'disk' => $disk,
            'storage_key' => $storageKey,
            'status' => $status,
            'ref_count' => 0,
            'dedup_scope' => 'tenant',
            'committed_at' => $status === BlobStatus::Committed ? now() : null,
        ]);

        return $blob;
    }

    private static function contentStore(): ContentStore
    {
        return app(Sha256ContentStore::class);
    }
}
