<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Vaulter\Documents\Contracts\DocumentContent;
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
    /**
     * @param array<string, mixed> $content     the JSON projection of `$collabState` in the stored binding schema
     * @param string|null          $stateFormat the encoding to publish, or null to preserve the binding's format
     */
    public function __construct(
        public CollaborationStateBinding $binding,
        public int $expectedStateVersion,
        public int $coveredThrough,
        public ?string $collabState,
        public array $content,
        public ?ActorRef $actor = null,
        public ?DocumentContent $preparedContent = null,
        public ?string $stateFormat = null,
    ) {
        if ($expectedStateVersion < 0 || $coveredThrough < 0) {
            throw new InvalidArgumentException('[Vaulter] A checkpoint names a generation, a state version and a covered sequence of at least 0.');
        }
        if ($collabState === '') {
            throw new InvalidArgumentException('[Vaulter] A checkpoint carries a binary state.');
        }
        if ($stateFormat !== null && $stateFormat !== $binding->stateFormat
            && ($collabState === null || !CollaborationStateBinding::acceptsFormat($binding->protocol, $binding->stateFormat, $stateFormat))) {
            throw new InvalidArgumentException('[Vaulter] Unsupported checkpoint format transition.');
        }
    }
}
