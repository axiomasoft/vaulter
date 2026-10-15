<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Uploads;

use Vaulter\Support\Identity;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Read-side lookups over v_upload_sessions. The resumable endpoints
 * address a session by its client upload_id handle; the sweeper scans the stale
 * candidates. No transactions, no mutations.
 */
final class UploadSessionReadRepository
{
    public function __construct(
        private readonly DriveConfig $driveConfig,
    ) {}

    public function find(int|string $id): ?UploadSession
    {
        $query = $this->query();

        return Identity::fitsKey($query->getModel(), $id) ? $query->find($id) : null;
    }

    /**
     * Resolve a session by its client UNIQUE upload_id handle — the key every
     * tus HEAD/PATCH/DELETE and S3 part/complete endpoint addresses it by. Null when
     * absent (a gone/never-existed session 404s at the controller).
     */
    public function findByUploadId(string $uploadId): ?UploadSession
    {
        // upload_id is a uuid column: any other string names no session (and PostgreSQL would fail the cast).
        return Str::isUuid($uploadId) ? $this->query()->where('upload_id', $uploadId)->first() : null;
    }

    /**
     * The sweeper candidates: OPEN sessions (initiated|uploading) PLUS stuck
     * Assembled sessions, whose expires_at is at or before $threshold.
     * Returns a Builder so the command chunks it. Backed by the (status, expires_at)
     * index.
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
     * The number of OPEN (initiated|uploading) sessions an actor currently holds
     * — the count `UploadSessionManager::initiate()` checks
     * against the configured cap BEFORE opening a new session. A null actor id
     * (unattributed/system-minted session) counts as 0 — there is no per-actor
     * bucket to bound.
     */
    public function countActiveForActor(int|string|null $actorId): int
    {
        if ($actorId === null) {
            return 0;
        }

        return $this->query()
            ->where('created_by', $actorId)
            ->whereIn('status', [UploadStatus::Initiated->value, UploadStatus::Uploading->value])
            ->count();
    }

    /**
     * @return Builder<UploadSession>
     */
    private function query(): Builder
    {
        $model = $this->driveConfig->model('upload_session');

        return app(TenancyBoundary::class)->constrain($model::query());
    }
}
