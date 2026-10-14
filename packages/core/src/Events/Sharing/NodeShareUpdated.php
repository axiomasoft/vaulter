<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Carbon\CarbonImmutable;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A live share link was changed without touching its token. Only the names of the
 * changed fields are reported: the new values (a password above all) stay out.
 */
final readonly class NodeShareUpdated extends DomainEvent
{
    /** @param list<string> $changed */
    public function __construct(
        public NodeShare $share,
        public array $changed,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DriveEventType
    {
        return DriveEventType::ShareUpdated;
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
        return [
            'node_id' => self::id($this->share->node_id),
            'changed' => $this->changed,
        ];
    }

    public function activityContext(): array
    {
        return [
            'share_id' => Identity::stringify($this->share->getKey()),
            'token_prefix' => $this->share->token_prefix,
            'changed' => $this->changed,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->share->node);
    }
}
