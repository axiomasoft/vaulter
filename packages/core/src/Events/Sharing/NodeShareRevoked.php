<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A bearer share-link was revoked. A revoked share never resolves
 * again (the resolver filters on revoked_at).
 */
final readonly class NodeShareRevoked extends DomainEvent
{
    public function __construct(
        public NodeShare $share,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): EventType
    {
        return EventType::ShareRevoked;
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
