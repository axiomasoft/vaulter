<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Vaulter\Models\DocumentComment;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * Broadcast on the document's private channel when a comment is added, so open
 * editors can show it live.
 */
final readonly class CommentAdded implements ShouldBroadcast
{
    public function __construct(
        public DocumentComment $comment,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(Config::channelPrefix().'.'.Identity::stringify($this->comment->document_id)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'comment.added';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->getKey(),
            'document_id' => $this->comment->document_id,
            'parent_id' => $this->comment->parent_id,
            'body' => $this->comment->body,
            'created_at' => $this->comment->created_at,
        ];
    }
}
