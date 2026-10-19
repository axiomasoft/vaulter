<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Repositories\Cas\BlobStoreRepository;
use Vaulter\Support\Identity;

/**
 * The integrity scrub of the CAS (D49): reads the object of a sample of committed, not chunked blobs through the
 * StorageDriver as a stream and compares its size and sha256 with the row. A missing object, a wrong size or a wrong
 * digest sets integrity_failed_at (the blob is no longer handed out); a clean check only moves integrity_checked_at.
 *
 * It never deletes bytes, pins or rows and writes no table of the drive (D28): the mark is media's own state, and only
 * an ingest of the same bytes lifts it (BlobStore). Reading every object of an object store is expensive, so a run is
 * bounded by a sample and a budget — the first of limit, max bytes and max seconds that is used up ends it — and takes
 * the blobs never checked first, then the least recently checked. A dry run reads the same bytes and writes nothing.
 *
 * An object that cannot be read because of an error (not because it is absent) is reported as unverified and left
 * unmarked: a failing store is not proof that the bytes are bad.
 */
#[Internal]
final readonly class BlobScrubber
{
    private const int CHUNK_BYTES = 1_048_576;

    private const int PAGE_SIZE = 200;

    public function __construct(
        private StorageDriver $driver,
        private BlobLock $lock,
        private BlobStoreRepository $blobs,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private MediaConfig $mediaConfig,
    ) {}

    /**
     * A null limit or budget takes the configuration (vaulter-media.scrub.*). $scope is a scope key
     * (`tenant:<id>`, `drive:<id>` or `off:<id>`), not a name.
     *
     * @return array{
     *     visited: int,
     *     checked: int,
     *     bytes: int,
     *     failures: list<array{id: string, reason: string}>,
     *     unverified: list<string>,
     *     stopped_by: string,
     * } bytes: every byte read in the run (failed reads and second reads under the lock included), the measure of the
     *   byte budget; stopped_by: limit, max_bytes, max_seconds or exhausted (nothing left to check in this run)
     */
    public function scrub(?int $limit = null, ?int $maxBytes = null, ?int $maxSeconds = null, ?string $scope = null, bool $dryRun = false): array
    {
        $limit = $this->mediaConfig->scrubLimit($limit);
        $maxBytes = $this->mediaConfig->scrubMaxBytes($maxBytes);
        $maxSeconds = $this->mediaConfig->scrubMaxSeconds($maxSeconds);

        $started = hrtime(true);
        $cutoff = CarbonImmutable::now('UTC');
        $visited = 0;
        $checked = 0;
        $bytes = 0;
        $failures = [];
        $unverified = [];
        $stoppedBy = 'exhausted';
        $cursor = null;

        while (true) {
            if ($visited >= $limit) {
                $stoppedBy = 'limit';
                break;
            }

            $size = min($limit - $visited, self::PAGE_SIZE);
            $page = $this->page(scope: $scope, cutoff: $cutoff, cursor: $cursor, size: $size);

            foreach ($page as $blob) {
                if ($maxBytes !== null && $bytes >= $maxBytes) {
                    $stoppedBy = 'max_bytes';
                    break 2;
                }

                if ($maxSeconds !== null && (hrtime(true) - $started) / 1_000_000_000 >= $maxSeconds) {
                    $stoppedBy = 'max_seconds';
                    break 2;
                }

                $checkedAt = $blob->getRawOriginal('integrity_checked_at');
                $cursor = [is_string($checkedAt) ? $checkedAt : null, $blob->getKey()];
                $visited++;

                // Every byte read counts against the budget: a read that fails half way and the second read of a
                // failure under the lock included.
                try {
                    $reason = $this->verify($blob, $bytes);
                } catch (Throwable) {
                    $unverified[] = Identity::stringify($blob->getKey());

                    continue;
                }

                // A stale verdict (the row or the object changed after the bytes were read) is dropped, not reported.
                $current = $dryRun ? true : $this->record($blob, $reason, $bytes);

                if ($current === null) {
                    $unverified[] = Identity::stringify($blob->getKey());

                    continue;
                }

                $checked++;

                if ($current && $reason !== null) {
                    $failures[] = ['id' => Identity::stringify($blob->getKey()), 'reason' => $reason];
                }
            }

            if ($page->count() < $size) {
                break;
            }
        }

        if (!$dryRun) {
            $this->emit(checked: $checked, bytes: $bytes, failures: $failures);
        }

        return [
            'visited' => $visited,
            'checked' => $checked,
            'bytes' => $bytes,
            'failures' => $failures,
            'unverified' => $unverified,
            'stopped_by' => $stoppedBy,
        ];
    }

    /**
     * Stream the object and compare it with the row; every byte read is added to $bytes as it is read. The read stops
     * as soon as the object is longer than the row says. An error while reading throws: that is unverified, not a
     * verdict. A driver answers get() with null both for an absent object and, on a disk that does not throw, for one
     * it failed to read: the object is missing only when the store also says it is not there.
     *
     * @return string|null missing, size, digest or null (intact)
     */
    private function verify(Blob $blob, int &$bytes): ?string
    {
        $stream = $this->driver->get($blob->storage_key, $blob->disk);

        if ($stream === null) {
            if ($this->driver->exists($blob->storage_key, $blob->disk)) {
                throw new RuntimeException('The object of blob '.Identity::stringify($blob->getKey()).' is there but could not be read.');
            }

            return 'missing';
        }

        try {
            $hash = hash_init('sha256');
            $read = 0;

            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException('Reading the object of blob '.Identity::stringify($blob->getKey()).' failed.');
                }

                hash_update($hash, $chunk);
                $read += strlen($chunk);
                $bytes += strlen($chunk);

                if ($read > $blob->size_bytes) {
                    break;
                }
            }
        } finally {
            fclose($stream);
        }

        if ($read !== $blob->size_bytes) {
            return 'size';
        }

        return hash_equals(strtolower($blob->sha256), hash_final($hash)) ? null : 'digest';
    }

    /**
     * Write the verdict under the lock of the blob. The row is read again: if the ingest healed it, GC took it or
     * another scrub recorded it while the bytes were read, the verdict is stale and nothing is written. An ingest or a
     * resurrection can also restore the object without touching a column, so a failing verdict is checked once more
     * under the lock (the object is read again; only failures pay for it, and the bytes go to $bytes) and stands only
     * if it is the same. Returns false for a stale verdict and null when the object cannot be read for the check
     * (unverified, nothing written).
     */
    private function record(Blob $seen, ?string $reason, int &$bytes): ?bool
    {
        return $this->database->transaction(function () use ($seen, $reason, &$bytes): ?bool {
            $this->lock->acquire(scope: $seen->scope_key, sha256: $seen->sha256);

            $fresh = $this->blobs->lockRow($seen);

            if ($fresh->status !== BlobStatus::Committed
                || $fresh->getRawOriginal('integrity_checked_at') !== $seen->getRawOriginal('integrity_checked_at')
                || $fresh->getRawOriginal('integrity_failed_at') !== $seen->getRawOriginal('integrity_failed_at')) {
                return false;
            }

            if ($reason !== null) {
                try {
                    $again = $this->verify($fresh, $bytes);
                } catch (Throwable) {
                    return null;
                }

                if ($again !== $reason) {
                    return false;
                }
            }

            $this->blobs->recordIntegrity($fresh, $reason === null);

            return true;
        });
    }

    /**
     * The next blobs of the queue: never checked first, then by the oldest check, then by key. A keyset page, so the
     * rows a run has written (and a dry run's rows, which stay as they are) are not seen twice; rows checked since
     * the start of the run are out of the queue.
     *
     * @param  array{0: string|null, 1: mixed}|null $cursor
     * @return Collection<int, Blob>
     */
    private function page(?string $scope, CarbonImmutable $cutoff, ?array $cursor, int $size): Collection
    {
        $model = $this->mediaConfig->blobModel();
        $key = (new $model)->getKeyName();
        $format = (new $model)->getDateFormat();

        /** @var Builder<Blob> $query */
        $query = $model::query()
            ->where('status', BlobStatus::Committed->value)
            ->where('chunked', false)
            ->where(static fn (Builder $q): Builder => $q->whereNull('integrity_checked_at')->orWhere('integrity_checked_at', '<', $cutoff->format($format)))
            ->orderByRaw('CASE WHEN integrity_checked_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('integrity_checked_at')
            ->orderBy($key)
            ->limit($size);

        if ($scope !== null) {
            $query->where('scope_key', $scope);
        }

        if ($cursor !== null) {
            [$checkedAt, $last] = $cursor;

            $query->where(static fn (Builder $q): Builder => $checkedAt === null
                ? $q->where(static fn (Builder $n): Builder => $n->whereNull('integrity_checked_at')->where($key, '>', $last))->orWhereNotNull('integrity_checked_at')
                : $q->where('integrity_checked_at', '>', $checkedAt)->orWhere(static fn (Builder $n): Builder => $n->where('integrity_checked_at', '=', $checkedAt)->where($key, '>', $last)));
        }

        return $query->get();
    }

    /** @param list<array{id: string, reason: string}> $failures */
    private function emit(int $checked, int $bytes, array $failures): void
    {
        $this->metrics->counter(name: 'vaulter_blobs_scrub_checked_total', amount: $checked);
        $this->metrics->counter(name: 'vaulter_blobs_scrub_bytes_total', amount: $bytes);

        $byReason = array_count_values(array_column($failures, 'reason'));

        foreach (['missing', 'size', 'digest'] as $reason) {
            $this->metrics->counter(name: 'vaulter_blobs_scrub_failed_total', amount: $byReason[$reason] ?? 0, labels: ['reason' => $reason]);
        }
    }
}
