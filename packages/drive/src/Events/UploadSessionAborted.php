<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Drive\Uploads\UploadSessionManager;

/**
 * A resumable-upload session was aborted by the client (DELETE) (§2.8 / F-P1.4-13):
 * the staged temp + any protocol-side state (S3 multipart) were already reclaimed by
 * {@see UploadSessionManager::abort()} — this is the audit
 * trace of that reclaim.
 */
final readonly class UploadSessionAborted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public UploadSession $session,
        public int|string|null $actorId = null,
    ) {}
}
