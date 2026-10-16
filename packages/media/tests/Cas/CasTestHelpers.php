<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests\Cas;

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Schema\IdStrategyResolver;

/**
 * Shared builders for the CAS slice tests (M1b): a real Storage→Node→File→Version
 * chain so attach()/detach() can hang real edges off a committed blob, plus a
 * helper to turn a string into a stream the ContentStore can stage.
 */
final class CasTestHelpers
{
    /**
     * Build a committed FileRevision (with its File + Node + Storage) to attach a
     * blob edge to.
     */
    public static function makeVersion(int $versionNo = 1, string $name = 'a.bin'): FileRevision
    {
        // One storage per host owner (the M1c owner-unique index): mint a fresh
        // owner_id per call so repeated makeVersion()s in one test don't collide.
        static $owner = 0;
        $owner++;

        // forceFill (P1.4/F-P1.8-02, F-P1.5-04): Storage/Node/File/FileRevision all
        // now carry a closed $fillable/$guarded list — this trusted fixture
        // builder bypasses it exactly like the production store repositories do.
        $storage = new Drive;
        $storage->forceFill([
            'owner_type' => 'team',
            'owner_id' => $owner,
            'name' => 'Disk',
        ]);
        self::write(static fn () => $storage->save());

        $node = new Node;
        $node->forceFill([
            'drive_id' => $storage->getKey(),
            'kind' => NodeKind::File,
            'name' => $name,
        ]);
        self::write(static fn () => $node->save());

        $file = new File;
        $file->forceFill([
            'node_id' => $node->getKey(),
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 0,
        ]);
        self::write(static fn () => $file->save());

        $version = new FileRevision;
        $version->forceFill([
            'file_id' => $file->getKey(),
            'number' => $versionNo,
            'sha256' => str_repeat('0', 64),
            'size_bytes' => 0,
            'original_name' => $name,
            'status' => 'committed',
        ]);
        self::write(static fn () => $version->save());

        return $version;
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
    public static function seedOrphan(string $bytes, string $scope = 't-default', int $ageHours = 48, string $disk = 'local'): string
    {
        $key = self::contentStore()->keyFor(scope: BlobScope::fromSegment($scope), sha256: hash('sha256', $bytes));

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
        string $scope = 't-default',
    ): Blob {
        $sha256 = hash('sha256', $bytes);
        $storageKey = self::contentStore()->keyFor(scope: BlobScope::fromSegment($scope), sha256: $sha256);

        StorageFacade::disk($bytesDisk ?? $disk)->put($storageKey, $bytes);

        // forceFill (P1.4/F-P1.5-04): Blob is fully guarded — bypassed here
        // exactly like BlobStoreRepository::insertCommitted() does.
        $blob = new Blob;
        $blob->forceFill([
            'tenant_id' => null,
            'drive_id' => null,
            'hash_algo' => 'sha256',
            'sha256' => $sha256,
            'size_bytes' => strlen($bytes),
            'disk' => $disk,
            'storage_key' => $storageKey,
            'status' => $status,
            'ref_count' => 0,
            'dedup_scope' => BlobScope::fromSegment($scope)->dedupScope(),
            'scope_key' => BlobScope::fromSegment($scope)->key(),
            'committed_at' => $status === BlobStatus::Committed ? now() : null,
        ]);
        self::write(static fn () => $blob->save());

        return $blob;
    }

    /**
     * Many blob rows in a few statements instead of one save per row: for tests that need
     * thousands of candidates. Same row shape as {@see seedBlobRow()} (the model serialises every
     * attribute, so casts and the id strategy apply); returned in seed order. $attributes are
     * merged into every row.
     *
     * @param  array<string, mixed> $attributes
     * @return list<Blob>
     */
    public static function seedBlobRows(
        int $count,
        string $bytesPrefix,
        BlobStatus $status,
        array $attributes = [],
        string $disk = 'local',
        string $scope = 't-default',
    ): array {
        $blobScope = BlobScope::fromSegment($scope);
        $resolver = app(IdStrategyResolver::class)->resolve(app(IdsConfig::class)->strategy());
        $rows = [];
        $hashes = [];

        for ($i = 0; $i < $count; $i++) {
            $bytes = $bytesPrefix.$i;
            $sha256 = hash('sha256', $bytes);
            $storageKey = self::contentStore()->keyFor(scope: $blobScope, sha256: $sha256);
            StorageFacade::disk($disk)->put($storageKey, $bytes);

            $blob = new Blob;
            $blob->forceFill([
                'tenant_id' => null,
                'drive_id' => null,
                'hash_algo' => 'sha256',
                'sha256' => $sha256,
                'size_bytes' => strlen($bytes),
                'disk' => $disk,
                'storage_key' => $storageKey,
                'status' => $status,
                'ref_count' => 0,
                'dedup_scope' => $blobScope->dedupScope(),
                'scope_key' => $blobScope->key(),
                'committed_at' => $status === BlobStatus::Committed ? now() : null,
                ...$attributes,
            ]);
            $newId = $resolver->newId();
            if ($newId !== null) {
                $blob->setAttribute($blob->getKeyName(), $newId);
            }
            $rows[] = $blob->getAttributes();
            $hashes[] = $sha256;
        }

        $model = new Blob;
        self::write(static function () use ($model, $rows): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                $model->getConnection()->table($model->getTable())->insert($chunk);
            }
        });

        $bySha = Blob::query()->whereIn('sha256', $hashes)->get()->keyBy('sha256');

        return array_map(static fn (string $sha256): Blob => $bySha[$sha256], $hashes);
    }

    /** Seed rows through the write scope so the guard lane stays on for the code under test. */
    public static function write(callable $callback): void
    {
        app(VaulterDatabase::class)->write($callback);
    }

    private static function contentStore(): ContentStore
    {
        return app(Sha256ContentStore::class);
    }
}
