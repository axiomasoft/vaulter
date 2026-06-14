<?php

declare(strict_types=1);

namespace DocVault\Events;

use DocVault\Models\Document;
use DocVault\Support\Config;
use DocVault\Support\Identity;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Broadcast on the document's private collaboration channel when its content is
 * synced, so connected clients can refresh / merge.
 */
final readonly class DocumentContentSynced implements ShouldBroadcast
{
    public function __construct(
        public Document $document,
        public int|string|null $actorId = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(Config::channelPrefix().'.'.Identity::stringify($this->document->getKey())),
        ];
    }

    public function broadcastAs(): string
    {
        return 'content.synced';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->document->getKey(),
            'actor_id' => $this->actorId,
            'updated_at' => $this->document->updated_at,
        ];
    }
}
