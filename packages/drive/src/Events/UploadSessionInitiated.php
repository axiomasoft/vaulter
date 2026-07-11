<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * A resumable-upload session was opened (§2.8 / F-P1.4-13): the create/initiate
 * side of the upload-session lifecycle audit gap — commit already lands
 * FileUploaded/NodeVersionCommitted, but create/abort/expire had no activity trace
 * at all before this.
 */
final readonly class UploadSessionInitiated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public UploadSession $session,
        public int|string|null $actorId = null,
    ) {}
}
