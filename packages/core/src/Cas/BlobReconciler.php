<?php

declare(strict_types=1);

namespace Vaulter\Cas;

use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Models\Cas\Blob;
use Vaulter\Repositories\Cas\BlobStoreRepository;
use Vaulter\Repositories\Drive\FileVersionBlobStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * Blob reconciliation (H.3): ref_count is a CACHE, not the truth, so it drifts and
 * must be rebuilt from the edge table — the real liveness source. Reconciliation
 * also surfaces the two integrity hazards a CAS must watch:
 *
 *  - DANGLING ROW   — a committed blob row whose canonical object is missing on
 *    disk (a half-promoted commit, a botched manual delete).
 *  - (orphan objects on disk with no row are detectable only with a full disk
 *    listing, which is disk-specific; we expose the row-side checks here and the
 *    command notes the disk-walk as the operator step.)
 *
 * It never deletes anything — it rewrites the ref_count cache and reports.
 */
final readonly class BlobReconciler
{
    public function __construct(
        private ContentStore $content,
        private BlobStoreRepository $blobs,
        private FileVersionBlobStoreRepository $edges,
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

        return [
            'reconciled' => $reconciled,
            'changed' => $changed,
            'dangling' => $dangling,
        ];
    }

    /**
     * @return class-string<Blob>
     */
    private function blobModel(): string
    {
        return Config::blobModel();
    }
}
