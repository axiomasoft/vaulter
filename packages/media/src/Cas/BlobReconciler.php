<?php

declare(strict_types=1);

namespace Vaulter\Media\Cas;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Configuration\MediaConfig;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Contracts\Cas\ListableContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Repositories\Cas\BlobStoreRepository;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Repositories\Drive\FileRevisionBlobStoreRepository;
use Vaulter\Support\Identity;

/**
 * Blob reconciliation: ref_count is a CACHE, not the truth, so it drifts and
 * must be rebuilt from the edge table — the real liveness source. Reconciliation
 * also surfaces the two integrity hazards a CAS must watch, the inverse images of
 * each other:
 *
 *  - DANGLING ROW   — a committed blob row whose canonical object is missing on
 *    disk (a half-promoted commit, a botched manual delete). Found by reconcile()
 *    walking the ROW side.
 *  - ORPHAN OBJECT  — bytes on disk with NO blob row at all (a commit promoted its
 *    bytes then its transaction rolled the row back; or leaked bytes). Found by
 *    sweepOrphans() walking the DISK side.
 *
 * reconcile() never deletes — it rewrites the ref_count cache and reports.
 * sweepOrphans() is report-only by default; with $prune it physically deletes true
 * orphans under the SAME per-(scope,sha256) lock + grace discipline the GC uses, so
 * a concurrent commit can never have its in-flight bytes swept out from under it.
 *
 * Two capability gates make the destructive path safe and BC-clean:
 *  - LISTING. The disk-walk needs the CAS driver to enumerate the disk. That seam
 *    lives on the optional {@see ListableContentStore} capability (NOT the base
 *    ContentStore contract — adding to it would break every external driver). When
 *    the active store is not listable, sweepOrphans() refuses cleanly (reports
 *    listingSupported=false) instead of failing; this also surfaces the S3 /
 *    non-FilesystemAdapter "cannot enumerate" case rather than silently skipping.
 *  - PRUNE SERIALISATION. The prune re-reads under the lock then deletes BYTES with
 *    NO row write, so it is ordered against a concurrent commit ONLY on drivers
 *    whose lock serialises a write-less section (PG advisory xact lock; MySQL FOR
 *    UPDATE). On SQLite that ordering never engages (acquire() is a no-op, FOR
 *    UPDATE is ignored) so a concurrent commit could INSERT the owning row between
 *    the recheck and the delete and we would delete LIVE bytes — therefore prune is
 *    REFUSED on SQLite (pruneSupported=false): detection still runs, deletion does
 *    not. Report-only works on EVERY driver. (The GC is immune: it writes a real
 *    markDeleting() that forces SQLite's write lock; the prune deliberately writes
 *    nothing, so it cannot lean on that.)
 */
#[Internal]
final readonly class BlobReconciler
{
    /** Both historical two-shard keys and lossless single-shard keys. */
    private const string KEY_PATTERN = '#^(?<scope>global|[ts](?:\d+|null|[0-9A-HJKMNP-TV-Z]{26})|g|[tdo]-~?[A-Za-z0-9_-]{1,128})/[0-9a-f]{2}/(?:[0-9a-f]{2}/)?(?<sha256>[0-9a-f]{64})$#';

    public function __construct(
        private ContentStore $content,
        private BlobStoreRepository $blobs,
        private FileRevisionBlobStoreRepository $edges,
        private BlobLock $lock,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private MediaConfig $mediaConfig,
    ) {}

    /**
     * Rebuild ref_count for every blob from its live edge count and collect the
     * dangling rows. Returns a report tally.
     *
     * @return array{reconciled: int, changed: int, dangling: list<string>}
     */
    public function reconcile(): array
    {
        $reconciled = 0;
        $changed = 0;
        $dangling = [];

        $this->metrics->timing(
            name: 'vaulter_reconcile_duration_seconds',
            operation: function () use (&$reconciled, &$changed, &$dangling): void {
                foreach ($this->blobModel()::query()->cursor() as $blob) {
                    /** @var Blob $blob */
                    $reconciled++;

                    $count = $this->edges->countEdges($blob);

                    if ($blob->ref_count !== $count) {
                        $this->blobs->setRefCount(blob: $blob, count: $count);
                        $changed++;
                    }

                    // A committed blob must have its bytes on disk; a missing object is a
                    // dangling row the operator should investigate (re-upload or purge).
                    if ($blob->status === BlobStatus::Committed && !$this->content->exists($blob->storage_key, $blob->disk)) {
                        $dangling[] = Identity::stringify($blob->getKey());
                    }
                }
            },
        );

        if ($reconciled > 0) {
            $this->metrics->counter(name: 'vaulter_reconcile_objects_total', amount: $reconciled);
        }

        return [
            'reconciled' => $reconciled,
            'changed' => $changed,
            'dangling' => $dangling,
        ];
    }

    /**
     * Walk the DISK for orphan objects — bytes that match the committed CAS key
     * layout but have NO blob row — and, when $prune AND the driver serialises the
     * write-less prune section, physically delete the true orphans.
     * Report-only by default (a safe dry run on EVERY driver).
     *
     * CAPABILITY GATES:
     *  - If the active store cannot enumerate the disk (not a {@see ListableContentStore},
     *    e.g. a streaming-only or non-FilesystemAdapter driver) the walk is REFUSED:
     *    returns listingSupported=false, scanned 0, no deletion — the command reports
     *    "listing unsupported", not a fatal error.
     *  - If $prune is requested but the driver does NOT serialise a write-less
     *    critical section (SQLite — see {@see BlobLock::serializesCriticalSection()}),
     *    pruning is REFUSED: detection still runs and orphans are reported, but
     *    pruneSupported=false and nothing is deleted (deleting under SQLite would
     *    race a concurrent commit and could destroy live bytes). Run report-only, or
     *    prune on the production PostgreSQL/MySQL.
     *
     * SAFETY (this deletes bytes when it does prune):
     *  1. A path is a candidate ONLY if it EXACTLY matches the committed key layout
     *     (KEY_PATTERN) AND keyFor(scope, sha256) re-derives the SAME path — so the
     *     shard bytes are the real shard of that sha256. Staging/tmp, resumable
     *     parts and foreign files never match and are skipped untouched.
     *  2. A matching path with ANY blob row (looked up DISK-AGNOSTICALLY, since
     *     storage_key is globally unique) is owned, not an orphan.
     *  3. A row-less object is a deletable orphan only once it is OLDER than the
     *     orphan grace — a just-promoted object may be a commit whose row is not yet
     *     durable; a fresher one is skipped.
     *  4. Pruning happens under the per-(scope,sha256) lock inside a transaction,
     *     RE-checking both the row-absence (under the MySQL row lock) and the grace
     *     AFTER taking the lock (a concurrent commit may have just created the row /
     *     re-touched the bytes), then deletes idempotently.
     *
     * @return array{scanned: int, orphans: list<string>, pruned: int, skipped: int, listingSupported: bool, pruneSupported: bool}
     */
    public function sweepOrphans(?string $disk = null, int $graceHours = 0, bool $prune = false): array
    {
        // The disk-walk seam is an OPTIONAL capability, not the base ContentStore
        // contract: a non-listable store cannot be walked, so refuse cleanly.
        $content = $this->content;

        if (!$content instanceof ListableContentStore) {
            return $this->unsupportedListing();
        }

        $disk ??= $this->mediaConfig->disk();
        $grace = $this->mediaConfig->orphanGraceHours($graceHours);

        // The prune deletes bytes with NO row write, so it is only safe on a driver
        // whose lock serialises a write-less section. On SQLite we still DETECT and
        // REPORT, but never delete.
        $pruneSupported = $this->lock->serializesCriticalSection();
        $doPrune = $prune && $pruneSupported;

        $scanned = 0;
        $orphans = [];
        $pruned = 0;
        $skipped = 0;

        foreach ($content->listKeys($disk) as $path) {
            $scanned++;

            $parsed = $this->parseManagedKey($path);

            // Not a committed CAS object (staging/tmp, resumable part, foreign file,
            // or a mis-sharded path that does not round-trip) — never touch it.
            if ($parsed === null) {
                $skipped++;

                continue;
            }

            // An object owned by a blob row (any status, ANY disk — storage_key is
            // globally unique) is not an orphan.
            if ($this->blobs->findByStorageKey(storageKey: $path) !== null) {
                $skipped++;

                continue;
            }

            // A row-less object younger than the grace may be a commit-in-progress
            // whose row is not yet durable — leave it for a later pass.
            if (!$this->isPastGrace(content: $content, path: $path, disk: $disk, graceHours: $grace)) {
                $skipped++;

                continue;
            }

            $orphans[] = $path;

            if (!$doPrune) {
                continue;
            }

            $this->pruneOrphan(content: $content, path: $path, disk: $disk, scope: $parsed['scope'], sha256: $parsed['sha256'], graceHours: $grace)
                ? $pruned++
                : $skipped++;
        }

        return [
            'scanned' => $scanned,
            'orphans' => $orphans,
            'pruned' => $pruned,
            'skipped' => $skipped,
            'listingSupported' => true,
            'pruneSupported' => $pruneSupported,
        ];
    }

    /**
     * The walk-refused result for a store that cannot enumerate the disk: nothing
     * scanned, nothing pruned, both capabilities reported off.
     *
     * @return array{scanned: int, orphans: list<string>, pruned: int, skipped: int, listingSupported: bool, pruneSupported: bool}
     */
    private function unsupportedListing(): array
    {
        return [
            'scanned' => 0,
            'orphans' => [],
            'pruned' => 0,
            'skipped' => 0,
            'listingSupported' => false,
            'pruneSupported' => false,
        ];
    }

    /**
     * Delete one orphan under the per-(scope,sha256) advisory lock (mirrors
     * BlobGarbageCollector::lockedTransaction): take the lock as the first statement,
     * then RE-CHECK that no row appeared and the object is still past grace before
     * the idempotent physical delete. Returns whether the object was deleted (false
     * ⇒ a concurrent commit won the race / the bytes were re-touched / already gone).
     *
     * Only ever reached on a driver whose lock serialises this write-less section
     * (sweepOrphans gates on {@see BlobLock::serializesCriticalSection()}); the
     * row-absence recheck takes the MySQL row lock so a concurrent INSERT contends.
     *
     * @param ContentStore&ListableContentStore $content the active store, already
     *                                                   confirmed listable by the caller so its age probe is usable here
     */
    private function pruneOrphan(ContentStore&ListableContentStore $content, string $path, string $disk, string $scope, string $sha256, int $graceHours): bool
    {
        return $this->database->transaction(function () use ($content, $path, $disk, $scope, $sha256, $graceHours): bool {
            $this->lock->acquire(scope: $scope, sha256: $sha256);

            // A commit that won the lock between the cheap scan and here has now
            // created the row — the bytes are owned, never delete them. Disk-agnostic
            // (storage_key is globally unique); lock: true takes the MySQL row lock.
            if ($this->blobs->findByStorageKey(storageKey: $path, lock: true) !== null) {
                return false;
            }

            // The same commit may have re-promoted (re-touched) the bytes; re-apply
            // the grace under the lock so a fresh object is never swept.
            if (!$this->isPastGrace(content: $content, path: $path, disk: $disk, graceHours: $graceHours)) {
                return false;
            }

            // Idempotent: a missing object is fine.
            $content->delete($path, $disk);

            return true;
        });
    }

    /**
     * Whether the object is older than the orphan grace. A null mtime (absent or the
     * disk cannot report it) is treated as NOT past grace, so an object whose age we
     * cannot establish is skipped, never deleted.
     */
    private function isPastGrace(ContentStore&ListableContentStore $content, string $path, string $disk, int $graceHours): bool
    {
        $lastModified = $content->lastModified($path, $disk);

        if ($lastModified === null) {
            return false;
        }

        return $lastModified < now()->subHours($graceHours)->getTimestamp();
    }

    /**
     * Classify a disk path as a committed CAS object and extract its (scope, sha256),
     * or null when it is not one. The match is exact AND verified by round-tripping
     * keyFor(scope, sha256) === $path, so the shard bytes in the path are guaranteed
     * to be the real shard of that sha256 (a mis-sharded or scope-spoofed path is
     * rejected). This is the primary guard that the sweep only ever touches genuine
     * canonical objects.
     *
     * @return array{scope: string, sha256: string}|null
     */
    private function parseManagedKey(string $path): ?array
    {
        if (preg_match(self::KEY_PATTERN, $path, $matches) !== 1) {
            return null;
        }

        try {
            $scope = BlobScope::fromSegment($matches['scope']);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $sha256 = $matches['sha256'];

        // Re-derive the canonical key from the parsed parts; the shard bytes must be
        // the real shard of this sha256 for the path to round-trip.
        if ($this->content->keyFor(scope: $scope, sha256: $sha256) !== $path) {
            return null;
        }

        return ['scope' => $scope->key(), 'sha256' => $sha256];
    }

    /**
     * @return class-string<Blob>
     */
    private function blobModel(): string
    {
        return $this->mediaConfig->blobModel();
    }
}
