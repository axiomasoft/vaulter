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
 * A comment was added to a node (ADR-0014 шаг 1). Broadcast on the node's private
 * collaboration channel (channel_prefix.{node_id}) — the SAME room open editors join
 * (M4b) — so a live thread updates without a poll. Dispatched inside the Action's unit of work and
 * delivered after the OUTERMOST commit: a rollback sends nothing.
 */
final readonly class CommentAdded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public NodeComment $comment,
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
        return 'node-comment.added';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->getKey(),
            'node_id' => $this->comment->node_id,
            'parent_id' => $this->comment->parent_id,
            'body' => $this->comment->body,
            'created_at' => $this->comment->created_at,
        ];
    }
}
