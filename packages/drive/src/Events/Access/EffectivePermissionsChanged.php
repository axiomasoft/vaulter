<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Access;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\AccessInvalidation;
use Vaulter\Drive\Enums\Access\InvalidationScope;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * The effective permissions of a node, a subtree, a drive or of every drive changed: a grant or a share came or
 * went, a node stopped inheriting, moved, went to the trash, came back or was purged, a drive was archived, unarchived
 * or deleted, or the host told the drive its roles, policy or tenants changed ({@see AccessInvalidation}). It is
 * recorded in the transaction of the change, so whoever keeps derived authority — a collaboration session token —
 * learns of it atomically with the change and checks the rights again. It reaches outbox consumers only: no activity
 * row (the change itself has one), no journal entry, no webhook.
 *
 * `reach` (the `scope` of its data) says how far the change goes. `reason` is the type of the change that caused it
 * (`grant.revoked`, `node.moved`, …) or the host's reason. An expiry of a grant or a share by time records nothing: an
 * expired right is refused when it is checked.
 */
#[Api]
final readonly class EffectivePermissionsChanged extends DriveEvent
{
    /** A reason: a lowercase identifier of at most 64 bytes (an event type or the host's own word). */
    public const string REASON_PATTERN = '/^[a-z][a-z0-9_.:-]{0,63}$/';

    public ?string $driveId;

    public ?string $nodeId;

    public ?string $tenantId;

    #[Internal]
    public function __construct(
        public InvalidationScope $reach,
        mixed $driveId,
        mixed $nodeId,
        mixed $tenantId,
        public string $profile,
        public string $reason,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
        $this->driveId = self::id($driveId);
        $this->nodeId = self::id($nodeId);
        $this->tenantId = self::id($tenantId);

        if (preg_match(self::REASON_PATTERN, $reason) !== 1) {
            throw new InvalidArgumentException('[Vaulter] An access invalidation reason must be a lowercase identifier of at most 64 bytes.');
        }
        $nodeScoped = $reach === InvalidationScope::Node || $reach === InvalidationScope::Subtree;
        if (($reach === InvalidationScope::Global) !== ($this->driveId === null) || $nodeScoped !== ($this->nodeId !== null)) {
            throw new InvalidArgumentException('[Vaulter] A '.$reach->value.' access invalidation names '.match ($reach) {
                InvalidationScope::Global => 'no drive and no node.',
                InvalidationScope::Drive => 'its drive and no node.',
                default => 'its drive and its node.',
            });
        }
    }

    /** The change reaches the node and, for a folder, everything under it. */
    #[Internal]
    public static function forNode(Node $node, string $reason, ActorRef $actor, string $correlationId): self
    {
        return new self(
            reach: $node->kind === NodeKind::Folder ? InvalidationScope::Subtree : InvalidationScope::Node,
            driveId: $node->getRawOriginal('drive_id'),
            nodeId: $node->getRawOriginal($node->getKeyName()),
            tenantId: $node->getRawOriginal('tenant_id'),
            profile: $node->profile,
            reason: $reason,
            actor: $actor,
            correlationId: $correlationId,
        );
    }

    /** The purge of a node subtree whose rows are gone: the drive, its tenant and profile come from the context. */
    #[Internal]
    public static function forPurgedSubtree(Drive $drive, mixed $nodeId, string $reason, ActorRef $actor, string $correlationId): self
    {
        return new self(
            reach: InvalidationScope::Subtree,
            driveId: $drive->getRawOriginal($drive->getKeyName()),
            nodeId: $nodeId,
            tenantId: $drive->getRawOriginal('tenant_id'),
            profile: $drive->profile,
            reason: $reason,
            actor: $actor,
            correlationId: $correlationId,
        );
    }

    #[Internal]
    public static function forDrive(Drive $drive, string $reason, ActorRef $actor, string $correlationId): self
    {
        return new self(
            reach: InvalidationScope::Drive,
            driveId: $drive->getRawOriginal($drive->getKeyName()),
            nodeId: null,
            tenantId: $drive->getRawOriginal('tenant_id'),
            profile: $drive->profile,
            reason: $reason,
            actor: $actor,
            correlationId: $correlationId,
        );
    }

    /** Every drive: a change the host cannot narrow to one drive (its roles, a deactivated tenant). */
    #[Internal]
    public static function global(string $reason, ActorRef $actor, string $correlationId): self
    {
        return new self(
            reach: InvalidationScope::Global,
            driveId: null,
            nodeId: null,
            tenantId: null,
            profile: '',
            reason: $reason,
            actor: $actor,
            correlationId: $correlationId,
        );
    }

    public function type(): DriveEventType
    {
        return DriveEventType::EffectivePermissionsChanged;
    }

    public function scope(): EventScope
    {
        return new EventScope(tenantId: $this->tenantId, driveId: $this->driveId, profile: $this->profile);
    }

    /** The access policy of the drive (`drive:<id>`) or of every drive (`global`). */
    public function subject(): EventSubject
    {
        return new EventSubject(type: 'access', id: $this->driveId === null ? 'global' : 'drive:'.Identity::stringify($this->driveId));
    }

    public function data(): array
    {
        return [
            'drive_id' => $this->driveId,
            'scope' => $this->reach->value,
            'node_id' => $this->nodeId,
            'reason' => $this->reason,
        ];
    }
}
