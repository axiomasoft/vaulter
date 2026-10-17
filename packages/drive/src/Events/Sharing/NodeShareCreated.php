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
 * A bearer share-link was minted on a node. Carries the persisted
 * NodeShare (token_hash only — never the raw token); the token prefix is recorded in
 * the activity feed only.
 */
#[Api]
final readonly class NodeShareCreated extends DomainEvent
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
        return DriveEventType::ShareCreated;
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
            'role' => $this->share->role_key,
            'role_version' => $this->share->role_version,
            'audience' => $this->share->audience->value,
            'scope' => $this->share->scope->value,
            'expires_at' => $this->share->expires_at?->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function activityContext(): array
    {
        return [
            'share_id' => Identity::stringify($this->share->getKey()),
            'token_prefix' => $this->share->token_prefix,
            'role' => $this->share->role_key,
            'role_version' => $this->share->role_version,
            'audience' => $this->share->audience->value,
            'scope' => $this->share->scope->value,
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->share->node);
    }
}
