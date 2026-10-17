<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * A checkpoint to publish through {@see CollaborativeState::publishCheckpoint()}: the binary Yjs state merged from the
 * previous checkpoint and the log of `$generation` through `$coveredThrough`, with its JSON projection.
 *
 * `$expectedStateVersion` is the document state version the merge started from (the head's checkpoint version): a
 * write that landed since then makes the publication lose (CAS). The projection is required — publishing binary state
 * under an older JSON would serve stale JSON as current. `$actor` is who the content event names; null is the system.
 */
#[Api]
final readonly class CheckpointPublication
{
    /** @param array<string, mixed> $content the JSON projection of `$yjsState` in the document type's current schema */
    public function __construct(
        public int $generation,
        public int $expectedStateVersion,
        public int $coveredThrough,
        public string $yjsState,
        public array $content,
        public ?ActorRef $actor = null,
    ) {
        if ($generation < 0 || $expectedStateVersion < 0 || $coveredThrough < 0) {
            throw new InvalidArgumentException('[Vaulter] A checkpoint names a generation, a state version and a covered sequence of at least 0.');
        }
        if ($yjsState === '') {
            throw new InvalidArgumentException('[Vaulter] A checkpoint carries a binary state.');
        }
    }
}
