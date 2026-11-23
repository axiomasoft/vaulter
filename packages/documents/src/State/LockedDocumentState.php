<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\DriveStatus;

/**
 * A document as {@see CollaborativeState::lock()} read it under the tree, node and document locks (T → N → D). It is
 * valid only inside the transaction that took the locks: `$generation` (`collab_epoch`), `$stateVersion` and
 * `$pending` cannot move until that transaction ends, except through the same contract.
 *
 * `$trashed` is reported, not refused: a trashed document still drains its pending edits, while the caller decides
 * whether it takes new ones.
 */
#[Api]
final readonly class LockedDocumentState
{
    /**
     * @param array<string, mixed> $content
     * @param DriveStatus|null     $driveStatus     the status of the drive, read under its shared lock; null: not usable
     * @param int|null             $collabStateSize the binary size of the checkpoint, null: there is none
     */
    #[Internal]
    public function __construct(
        public string $documentId,
        public string $nodeId,
        public string $driveId,
        public string $type,
        public int $schemaVersion,
        public int $generation,
        public int $stateVersion,
        public ?PendingMarker $pending,
        public bool $trashed,
        public ?CollaborationStateBinding $binding,
        public ?string $collabState,
        public array $content,
        public ?DriveStatus $driveStatus = null,
        public ?int $collabStateSize = null,
        public string $writeMode = 'snapshot',
        public ?string $tenantId = null,
    ) {}
}
