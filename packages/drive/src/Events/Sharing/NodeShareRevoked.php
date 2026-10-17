<?php

declare(strict_types=1);

namespace Vaulter\Drive\Events\Sharing;

use Carbon\CarbonImmutable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A bearer share-link was revoked. A revoked share never resolves
 * again (the resolver filters on revoked_at).
 */
#[Api]
final readonly class NodeShareRevoked extends DomainEvent
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
        return DriveEventType::ShareRevoked;
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
