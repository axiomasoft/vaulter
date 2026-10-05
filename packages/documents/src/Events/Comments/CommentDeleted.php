<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Comments;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * A node comment was permanently deleted (ADR-0014 шаг 1 — no soft-delete). Broadcast
 * on the node's private collaboration channel so open editors drop it live. The model
 * still carries its id/node_id in memory after the delete, so the payload is complete.
 */
final readonly class CommentDeleted implements ShouldBroadcast
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
            new PrivateChannel(Config::channelPrefix().'.'.Identity::stringify($this->comment->node_id)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'node-comment.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->getKey(),
            'node_id' => $this->comment->node_id,
        ];
    }
}
