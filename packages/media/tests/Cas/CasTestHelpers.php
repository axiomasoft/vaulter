<?php

declare(strict_types=1);

namespace Vaulter\Media\Tests\Cas;

use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\IdsConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Schema\IdStrategyResolver;

/**
 * Shared builders for the CAS slice tests (M1b): pins of an opaque test owner (D23 —
 * media knows no drive), blob rows and orphan objects, plus a helper to turn a string
 * into a stream the ContentStore can stage.
 */
final class CasTestHelpers
{
    /** The owner type of the pins these tests write: media knows no owner (D23). */
    public const string OWNER = 'test.owner';

    /** A fresh pin reference key, unique within the test process. */
    public static function reference(string $name = 'ref'): string
    {
        static $sequence = 0;
        $sequence++;

        return $name.'#'.$sequence;
    }

    /** Pin $blob through the pin protocol (BlobPins), in a transaction of its own as an owner would. */
    public static function pin(Blob $blob, string $reference = 'ref', string $ownerType = self::OWNER): void
    {
        app(VaulterDatabase::class)->transaction(static fn () => app(BlobPins::class)->pin($blob, $ownerType, $reference));
    }

    /** Remove a pin through the protocol. */
    public static function unpin(Blob $blob, string $reference = 'ref', string $ownerType = self::OWNER): void
    {
        app(VaulterDatabase::class)->transaction(static fn () => app(BlobPins::class)->unpin($blob, $ownerType, $reference));
    }

    /**
     * Write a pin row directly, bypassing the protocol — only to put the garbage collector in front of a state the
     * protocol never produces (a pinned tombstoned or pending blob) and prove its re-check under the lock.
     */
    public static function seedPin(Blob|int|string $blob, string $reference = 'seeded', string $ownerType = self::OWNER): void
    {
        $row = [
            'blob_id' => $blob instanceof Blob ? $blob->getKey() : $blob,
            'owner_type' => $ownerType,
            'reference_key' => $reference,
            'created_at' => now(),
        ];
        $id = app(IdStrategyResolver::class)->resolve(app(IdsConfig::class)->strategy())->newId();

        app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table('blob_references'))
            ->insert($id === null ? $row : ['id' => $id, ...$row]);
    }

    /** The number of pins of $blob. */
    public static function pins(Blob $blob): int
    {
        return app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table('blob_references'))
            ->where('blob_id', $blob->getKey())->count();
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
            'storage_id' => null,
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
                'storage_id' => null,
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
