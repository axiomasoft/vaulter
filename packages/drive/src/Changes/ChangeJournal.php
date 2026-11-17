<?php

declare(strict_types=1);

namespace Vaulter\Drive\Changes;

use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Drive\Enums\Changes\ChangeReason;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Repositories\Changes\ChangeEntryRepository;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Events\DomainEvent;

/**
 * Writes the change journal of a drive: one entry per journalled
 * event, numbered by the drive's head row. Only {@see ChangeJournalSink} calls it, as the
 * last step of the mutation's transaction.
 *
 * The seq is taken by an UPDATE of the head row, the last lock of the transaction, and
 * that lock lasts until the commit. A second mutation of the drive waits for it, so
 * entries commit in seq order and a reader who sees last_seq = n can read every entry
 * up to n: there are no gaps that a later commit could fill.
 *
 * Entries are compacted to the latest state of a node: an upsert replaces the earlier
 * upserts it makes redundant. A remove is never compacted away, and neither is an
 * entry a weaker one would hide: the read projection turns an invisible node's move or
 * access change into a remove, so a later rename must not erase it.
 */
#[Internal]
final readonly class ChangeJournal
{
    public function __construct(
        private ChangeHeadRepository $heads,
        private ChangeEntryRepository $entries,
    ) {}

    /**
     * Why a reader who cannot see the node any more loses it, by the type of the
     * upsert that changed it; a type without a reason is skipped for such a reader.
     */
    public static function invisibilityReason(string $eventType): ?ChangeReason
    {
        return match ($eventType) {
            DriveEventType::NodeMoved->value => ChangeReason::MovedOut,
            DriveEventType::GrantCreated->value, DriveEventType::GrantRevoked->value, DriveEventType::InheritanceChanged->value => ChangeReason::PermissionLost,
            default => null,
        };
    }

    /** The seq of the new entry, or null when the event is already journalled. */
    public function append(DomainEvent $event, JournalEffect $effect): ?int
    {
        $scope = $event->scope();
        $driveId = $scope->driveId ?? throw new LogicException('[Vaulter] A journalled event needs a drive: '.$event->type()->value.'.');
        if ($effect->operation->value !== $event->type()->spec()->journal) {
            throw new LogicException('[Vaulter] The journal entry of '.$event->type()->value.' does not match its catalogue operation.');
        }
        if ($this->entries->exists($event->eventId)) {
            return null;
        }

        if (!$this->heads->advance($driveId)) {
            // A drive created before the journal existed, or outside the provisioner.
            $this->heads->create($driveId, $scope->tenantId);
            if (!$this->heads->advance($driveId)) {
                throw new LogicException('[Vaulter] The change journal head of drive '.$driveId.' cannot be created.');
            }
        }
        $seq = $this->heads->find($driveId)['last_seq'] ?? throw new LogicException('[Vaulter] The change journal head of drive '.$driveId.' disappeared.');

        $type = $event->type()->value;
        $this->compact($driveId, $effect, $type);
        $this->entries->insert([
            'drive_id' => $driveId,
            'seq' => $seq,
            'tenant_id' => $scope->tenantId,
            'change_id' => $event->eventId,
            'event_type' => $type,
            'node_id' => $effect->nodeId,
            'operation' => $effect->operation->value,
            'reason' => $effect->reason?->value,
            'subtree' => $effect->subtree,
            'node_sequence' => $effect->nodeSequence,
            'occurred_at' => $event->occurredAt,
        ]);

        return $seq;
    }

    private function compact(string $driveId, JournalEffect $effect, string $type): void
    {
        if ($effect->operation === ChangeOperation::Remove || $effect->subtree) {
            $this->entries->compact($driveId, $effect->nodeId, onlyWithoutSubtree: false, keptTypes: []);

            return;
        }
        if (self::invisibilityReason($type) !== null) {
            $this->entries->compact($driveId, $effect->nodeId, onlyWithoutSubtree: true, keptTypes: []);

            return;
        }
        $this->entries->compact($driveId, $effect->nodeId, onlyWithoutSubtree: true, keptTypes: self::reasonTypes());
    }

    /** @return list<string> */
    private static function reasonTypes(): array
    {
        return array_values(array_filter(
            array_map(static fn (DriveEventType $type): string => $type->value, DriveEventType::cases()),
            static fn (string $type): bool => self::invisibilityReason($type) !== null,
        ));
    }
}
