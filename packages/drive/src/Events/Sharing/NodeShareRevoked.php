<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Sharing;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A bearer share-link was revoked. A revoked share never resolves
 * again (the resolver filters on revoked_at).
 */
#[Api]
final readonly class NodeShareRevoked extends DriveEvent
{
    public ShareLinkSnapshot $share;

    private NodeShare $shareRow;

    #[Internal]
    public function __construct(
        NodeShare $share,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->shareRow = $share;
        $this->share = app(AccessSnapshotMapper::class)->share($share);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::ShareRevoked;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->shareRow->node);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'share', id: $this->shareRow->getKey());
    }

    public function data(): array
    {
        return ['node_id' => self::id($this->shareRow->node_id)];
    }

    public function activityContext(): array
    {
        return [
            'share_id' => Identity::stringify($this->shareRow->getKey()),
            'token_prefix' => $this->shareRow->token_prefix,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->shareRow->node);
    }
}
