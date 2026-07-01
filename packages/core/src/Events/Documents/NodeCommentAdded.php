<?php

declare(strict_types=1);

namespace Vaulter\Events\Documents;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Vaulter\Models\Documents\NodeComment;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * A comment was added to a node (ADR-0014 шаг 1). Broadcast on the node's private
 * collaboration channel (channel_prefix.{node_id}) — the SAME room open editors join
 * (M4b) — so a live thread updates without a poll. Dispatched by the Action AFTER the
 * insert transaction commits.
 */
final readonly class NodeCommentAdded implements ShouldBroadcast
{
    public function __construct(
        public NodeComment $comment,
    ) {}

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
