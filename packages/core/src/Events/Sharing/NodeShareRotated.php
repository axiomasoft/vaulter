<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A share link's token was replaced. The share carries the new token's hash and
 * prefix; neither reaches the webhook, the prefix is recorded in the activity feed.
 */
#[Api]
final readonly class NodeShareRotated extends DomainEvent
{
    #[Internal]
    public function __construct(
        public NodeShare $share,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::ShareRotated;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->share->node);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'share', id: $this->share->getKey());
    }

    public function data(): array
    {
        return ['node_id' => self::id($this->share->node_id)];
    }

    public function activityContext(): array
    {
        return [
            'share_id' => Identity::stringify($this->share->getKey()),
            'token_prefix' => $this->share->token_prefix,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->share->node);
    }
}
