<?php

declare(strict_types=1);

namespace Vaulter\Drive\ValueObjects\Events;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Drive\Enums\Changes\ChangeReason;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;

/**
 * The change-journal entry an event writes: one entry for the node the
 * event is about, never one per descendant. `subtree` tells a syncing client to
 * re-read the folder's subtree, because the change reaches every descendant (a move,
 * a copy, a restore, an access change).
 *
 * @see DomainEvent::journal()
 */
#[Internal]
final readonly class JournalEffect
{
    public string $nodeId;

    public function __construct(
        mixed $nodeId,
        public ChangeOperation $operation,
        public ?ChangeReason $reason = null,
        public bool $subtree = false,
        public ?int $nodeSequence = null,
    ) {
        $this->nodeId = Identity::stringify($nodeId);
    }

    public static function upsert(mixed $nodeId, bool $subtree = false, ?int $nodeSequence = null): self
    {
        return new self(nodeId: $nodeId, operation: ChangeOperation::Upsert, subtree: $subtree, nodeSequence: $nodeSequence);
    }

    public static function remove(mixed $nodeId, ChangeReason $reason, ?int $nodeSequence = null): self
    {
        return new self(nodeId: $nodeId, operation: ChangeOperation::Remove, reason: $reason, nodeSequence: $nodeSequence);
    }
}
