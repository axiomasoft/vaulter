<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;

/**
 * The pending-state marker of a document (D26): edits of collaboration generation `$generation` up to the log sequence
 * `$throughSeq` are committed but not in the working state yet. While a document carries it, its JSON projection and
 * binary checkpoint are stale, so documents neither serves nor replaces them (409 `document_state_pending`).
 * Ids are strings, the way every public id is compared.
 */
#[Api]
final readonly class PendingMarker
{
    #[Internal]
    public function __construct(
        public string $documentId,
        public string $nodeId,
        public int $generation,
        public int $throughSeq,
    ) {}

    /** Whether a checkpoint of `$generation` covering the log through `$coveredThrough` covers this marker. */
    public function coveredBy(int $generation, int $coveredThrough): bool
    {
        return $generation === $this->generation && $this->throughSeq <= $coveredThrough;
    }
}
