<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Sharing;

use Carbon\CarbonImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
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
 * A live share link was changed without touching its token. Only the names of the
 * changed fields are reported: the new values (a password above all) stay out.
 */
#[Api]
final readonly class NodeShareUpdated extends DriveEvent
{
    public ShareLinkSnapshot $share;

    private NodeShare $shareRow;

    /** @param list<string> $changed */
    #[Internal]
    public function __construct(
        AccessSnapshotMapper $accessSnapshots,
        NodeShare $share,
        public array $changed,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->shareRow = $share;
        $this->share = $accessSnapshots->share($share);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::ShareUpdated;
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
        return [
            'node_id' => self::id($this->shareRow->node_id),
            'changed' => $this->changed,
        ];
    }

    public function activityContext(): array
    {
        return [
            'share_id' => Identity::stringify($this->shareRow->getKey()),
            'token_prefix' => $this->shareRow->token_prefix,
            'changed' => $this->changed,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->shareRow->node);
    }
}
