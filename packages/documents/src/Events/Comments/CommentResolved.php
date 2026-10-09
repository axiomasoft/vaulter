<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Comments;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Support\Identity;

/**
 * A node comment thread was marked resolved (ADR-0014 шаг 1). Broadcast on the node's
 * private collaboration channel so open editors reflect the resolution live. Carries
 * the actor who resolved it (also stamped as resolved_by on the row).
 */
final readonly class CommentResolved implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public NodeComment $comment,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
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
