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
 * A bearer share-link was minted on a node. Carries the persisted
 * NodeShare (token_hash only — never the raw token); the token prefix is recorded in
 * the activity feed only.
 */
#[Api]
final readonly class NodeShareCreated extends DriveEvent
{
    public ShareLinkSnapshot $share;

    private NodeShare $shareRow;

    #[Internal]
    public function __construct(
        AccessSnapshotMapper $accessSnapshots,
        NodeShare $share,
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
        return DriveEventType::ShareCreated;
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
            'role' => $this->shareRow->role_key,
            'role_version' => $this->shareRow->role_version,
            'audience' => $this->shareRow->audience->value,
            'scope' => $this->shareRow->scope->value,
            'expires_at' => $this->shareRow->expires_at?->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function activityContext(): array
    {
        return [
            'share_id' => Identity::stringify($this->shareRow->getKey()),
            'token_prefix' => $this->shareRow->token_prefix,
            'role' => $this->shareRow->role_key,
            'role_version' => $this->shareRow->role_version,
            'audience' => $this->shareRow->audience->value,
            'scope' => $this->shareRow->scope->value,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->shareRow->node);
    }
}
