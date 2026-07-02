<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Support\Config;

/**
 * Read-side lookups over v_upload_sessions (§2.8 / M3a). The resumable endpoints
 * address a session by its client upload_id handle; the sweeper scans the stale
 * candidates. No transactions, no mutations.
 */
final class UploadSessionReadRepository
{
    public function find(int|string $id): ?UploadSession
    {
        return $this->query()->find($id);
    }

    /**
     * Resolve a session by its client UNIQUE upload_id handle (§2.8) — the key every
     * tus HEAD/PATCH/DELETE and S3 part/complete endpoint addresses it by. Null when
     * absent (a gone/never-existed session 404s at the controller).
     */
    public function findByUploadId(string $uploadId): ?UploadSession
    {
        return $this->query()->where('upload_id', $uploadId)->first();
    }

    /**
     * The §2.8 sweeper candidates: OPEN sessions (initiated|uploading) whose
     * expires_at is at or before $threshold. Returns a Builder so the command chunks
     * it. Backed by the (status, expires_at) index.
     *
     * @return Builder<UploadSession>
     */
    public function sweepable(Carbon $threshold): Builder
    {
        return $this->query()
            ->sweepable($threshold)
            ->orderBy('expires_at');
    }

    /**
     * @return Builder<UploadSession>
     */
    private function query(): Builder
    {
        $model = Config::uploadSessionModel();

        return $model::query();
    }
}
