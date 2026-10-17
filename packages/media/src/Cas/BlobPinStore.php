<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Closure;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Repositories\Cas\BlobStoreRepository;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Support\Identity;

/**
 * The only writer of v_blob_references (D23). A pin is the liveness truth of a blob: the garbage collector and
 * the reconciler read these rows and nothing of an owner's tables.
 *
 * pin() runs the protocol shared with the sweep, inside the caller's transaction: take the per-(scope, sha256)
 * lock, re-read the blob FOR UPDATE, then decide on the fresh status —
 *  - committed: pin;
 *  - tombstoned: pin only when the object still exists, committing the row again (the write also
 *    conflicts with a SERIALIZABLE sweep that read the row before, so that sweep retries and sees the pin);
 *  - pending, deleting or deleted: refuse — a pending row belongs to its commit; a sweep removes the object
 *    before it records the status, so its rollback can leave a tombstoned row without bytes, and a deleted row
 *    has none.
 * A refusal is {@see BlobUnavailableException}; a caller that holds the bytes commits them again.
 *
 * unpin() takes no lock: removing a pin only makes the blob a candidate, and both GC phases re-read the pins
 * under the lock before they act.
 */
#[Internal]
final readonly class BlobPinStore implements BlobPins
{
    private const int CHUNK = 500;

    private const string OWNER_TYPE = '/^[a-z][a-z0-9_-]*\.[a-z0-9_.-]+$/D';

    public function __construct(
        private VaulterDatabase $database,
        private BlobLock $lock,
        private BlobStoreRepository $blobs,
        private ContentStore $content,
        private DatabaseConfig $databaseConfig,
        private IdStrategyResolver $ids,
    ) {}

    public function pin(Blob $blob, string $ownerType, string $referenceKey): void
    {
        $this->assertReference(method: 'pin', ownerType: $ownerType, referenceKey: $referenceKey);

        $this->lock->acquire(scope: $blob->scope_key, sha256: $blob->sha256);
        $fresh = $this->blobs->lockRow($blob);

        match ($fresh->status) {
            BlobStatus::Committed => null,
            BlobStatus::Tombstoned => $this->recommit($fresh),
            BlobStatus::Pending, BlobStatus::Deleting, BlobStatus::Deleted => throw BlobUnavailableException::forBlob($fresh->id, $fresh->status),
        };

        $pinned = $this->query()
            ->where('blob_id', $fresh->getKey())
            ->where('owner_type', $ownerType)
            ->where('reference_key', $referenceKey)
            ->exists();

        if ($pinned) {
            return;
        }

        $row = [
            'blob_id' => $fresh->getKey(),
            'owner_type' => $ownerType,
            'reference_key' => $referenceKey,
            'created_at' => now(),
        ];
        $id = $this->ids->resolve()->newId();

        $this->query()->insert($id === null ? $row : ['id' => $id, ...$row]);
    }

    public function unpin(Blob|int|string $blob, string $ownerType, string $referenceKey): void
    {
        $this->assertReference(method: 'unpin', ownerType: $ownerType, referenceKey: $referenceKey);

        $this->query()
            ->where('blob_id', $blob instanceof Blob ? $blob->getKey() : $blob)
            ->where('owner_type', $ownerType)
            ->where('reference_key', $referenceKey)
            ->delete();
    }

    public function releaseOwner(string $ownerType, ?Closure $keys = null): int
    {
        $this->assertReference(method: 'releaseOwner', ownerType: $ownerType, referenceKey: null);

        if ($keys === null) {
            return $this->query()->where('owner_type', $ownerType)->delete();
        }

        $released = 0;
        $chunk = [];
        foreach ($keys() as $key) {
            $chunk[] = $key;
            if (count($chunk) === self::CHUNK) {
                $released += $this->query()->where('owner_type', $ownerType)->whereIn('reference_key', $chunk)->delete();
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $released += $this->query()->where('owner_type', $ownerType)->whereIn('reference_key', $chunk)->delete();
        }

        return $released;
    }

    public function countByOwner(): array
    {
        $counts = [];
        foreach ($this->query()->select('owner_type')->selectRaw('count(*) as pins')->groupBy('owner_type')->orderBy('owner_type')->get() as $row) {
            $counts[is_string($row->owner_type) ? $row->owner_type : ''] = is_numeric($row->pins) ? (int) $row->pins : 0;
        }

        return $counts;
    }

    /** Whether any owner pins the blob — the check both GC phases run under the lock. */
    public function isPinned(Blob|int|string $blob): bool
    {
        return $this->query()->where('blob_id', $blob instanceof Blob ? $blob->getKey() : $blob)->exists();
    }

    /**
     * The number of pins of each of the given blobs that has any, keyed by the stringified blob key.
     *
     * @param  list<int|string>   $blobIds
     * @return array<string, int>
     */
    public function countsFor(array $blobIds): array
    {
        $counts = [];
        foreach (array_chunk($blobIds, self::CHUNK) as $chunk) {
            $rows = $this->query()->select('blob_id')->selectRaw('count(*) as pins')->whereIn('blob_id', $chunk)->groupBy('blob_id')->get();
            foreach ($rows as $row) {
                $counts[Identity::stringify($row->blob_id)] = is_numeric($row->pins) ? (int) $row->pins : 0;
            }
        }

        return $counts;
    }

    /**
     * A tombstoned blob is pinned only while its object exists; it is then committed again under the
     * lock, the way a commit resurrects it.
     */
    private function recommit(Blob $blob): void
    {
        if (!$this->content->exists($blob->storage_key, $blob->disk)) {
            throw BlobUnavailableException::forBlob($blob->id, $blob->status);
        }

        $this->blobs->resurrect(blob: $blob);
    }

    private function assertReference(string $method, string $ownerType, ?string $referenceKey): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('[Vaulter] BlobPins::'.$method.'() must run inside the caller\'s VaulterDatabase::transaction(), next to the owner row it pins for.');
        }

        if (strlen($ownerType) > 64 || preg_match(self::OWNER_TYPE, $ownerType) !== 1) {
            throw new InvalidArgumentException('[Vaulter] A pin owner type is "<module>.<name>" of at most 64 characters, not "'.$ownerType.'".');
        }

        if ($referenceKey !== null && ($referenceKey === '' || strlen($referenceKey) > 191)) {
            throw new InvalidArgumentException('[Vaulter] A pin reference key has 1 to 191 characters.');
        }
    }

    private function query(): Builder
    {
        return $this->database->connection()->table($this->databaseConfig->table('blob_references'));
    }
}
