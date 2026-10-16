<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Comments;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node comment thread was marked resolved (docs/adr/0014-legacy-docvault-strangler.md шаг 1). Broadcast on the node's
 * private collaboration channel so open editors reflect the resolution live. The
 * actor who resolved it is also stamped as resolved_by on the row.
 */
#[Api]
final readonly class CommentResolved extends DomainEvent implements ShouldBroadcast
{
    #[Internal]
    public function __construct(
        public NodeComment $comment,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DocumentEventType
    {
        return DocumentEventType::CommentResolved;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->comment->node);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'comment', id: $this->comment->getKey());
    }

    public function data(): array
    {
        return [
            'comment_id' => self::id($this->comment->getKey()),
            'node_id' => self::id($this->comment->node_id),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->comment->node);
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(app(DocumentsConfig::class)->channelPrefix().'.'.Identity::stringify($this->comment->node_id)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'node-comment.resolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->getKey(),
            'node_id' => $this->comment->node_id,
            'resolved_at' => $this->comment->resolved_at,
            'resolved_by' => $this->comment->resolved_by,
        ];
    }
}
