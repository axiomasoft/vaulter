<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Access;

use Carbon\CarbonImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Drive\ValueObjects\Events\JournalEffect;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A per-node ACL grant was created or updated. Host listeners receive it
 * after the grant transaction commits.
 */
#[Api]
final readonly class NodeGrantCreated extends DriveEvent
{
    public GrantSnapshot $grant;

    private NodeGrant $grantRow;

    #[Internal]
    public function __construct(
        AccessSnapshotMapper $accessSnapshots,
        NodeGrant $grant,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->grantRow = $grant;
        $this->grant = $accessSnapshots->grant($grant);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::GrantCreated;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->grantRow->node);
    }

    public function subject(): EventSubject
    {
        return self::nodeSubject($this->grantRow->node);
    }

    public function journal(): JournalEffect
    {
        return self::nodeUpsert($this->grantRow->node, subtree: true);
    }

    public function data(): array
    {
        return [
            'grant_id' => self::id($this->grantRow->getKey()),
            'subject' => ['type' => $this->grantRow->subject_type, 'id' => self::id($this->grantRow->subject_id)],
            'role' => $this->grantRow->role_key,
            'role_version' => $this->grantRow->role_version,
            'scope' => $this->grantRow->scope->value,
        ];
    }

    public function activityContext(): array
    {
        return [
            'grant_id' => Identity::stringify($this->grantRow->getKey()),
            'subject_type' => $this->grantRow->subject_type,
            'subject_id' => Identity::stringify($this->grantRow->subject_id),
            'role' => $this->grantRow->role_key,
            'role_version' => $this->grantRow->role_version,
            'scope' => $this->grantRow->scope->value,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->grantRow->node);
    }
}
