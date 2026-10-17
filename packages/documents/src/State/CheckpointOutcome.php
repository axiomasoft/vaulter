<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/**
 * What {@see CollaborativeState::publishCheckpoint()} did. A publication that lost its CAS (`$published` false: the
 * generation or the state version moved) changed nothing; `$generation` and `$stateVersion` are then the document's
 * current ones, so the caller tells a closed generation (`$generation` differs from its own) from a lost race.
 *
 * After a publication `$changed` says whether the working state differed, `$stateVersion` is the version the
 * checkpoint now holds, and `$pending` is the marker left: null when the checkpoint covered it, the marker of edits
 * committed past the covered sequence otherwise.
 */
#[Api]
final readonly class CheckpointOutcome
{
    #[Internal]
    public function __construct(
        public bool $published,
        public bool $changed,
        public int $generation,
        public int $stateVersion,
        public ?PendingMarker $pending,
    ) {}
}
