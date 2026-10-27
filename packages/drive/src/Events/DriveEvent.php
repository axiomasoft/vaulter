<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Events\DomainEvent;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * An occurrence in the tree of a drive: what the drive's own sinks read besides the kernel's envelope — the target
 * of its activity row and its change-journal entry. The drive's and the documents' events extend it; the kernel's
 * {@see DomainEvent} knows neither.
 */
#[Api]
abstract readonly class DriveEvent extends DomainEvent
{
    public function activityTarget(): ?ActivityTarget
    {
        return null;
    }

    /**
     * The change-journal entry of this occurrence, or null when the type is not
     * journalled. Its operation matches the `journal` of the type's spec.
     */
    public function journal(): ?JournalEffect
    {
        return null;
    }

    #[Internal]
    protected static function nodeScope(Node $node): EventScope
    {
        return new EventScope(tenantId: $node->tenant_id, driveId: $node->drive_id, profile: $node->profile);
    }

    #[Internal]
    protected static function nodeSubject(Node $node): EventSubject
    {
        return new EventSubject(type: 'node', id: $node->getKey());
    }

    /**
     * The upsert entry of a node change. With $subtree the entry asks clients to re-read
     * the subtree, which only means something for a folder.
     */
    #[Internal]
    protected static function nodeUpsert(Node $node, bool $subtree = false, ?int $sequence = null): JournalEffect
    {
        return JournalEffect::upsert(nodeId: $node->getKey(), subtree: $subtree && $node->kind === NodeKind::Folder, nodeSequence: $sequence);
    }
}
