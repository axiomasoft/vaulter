<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Uploads;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * A stale resumable-upload session was reclaimed by the sweeper (§2.8 / F-P1.4-13):
 * dispatched by {@see UploadSessionManager::expire()} after
 * the staged temp + protocol-side state were released. The sweeper runs outside an
 * HTTP request (console/schedule), so actorId is null here — a system reclaim, not
 * an actor action.
 */
final readonly class UploadExpired implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public UploadSession $session,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
