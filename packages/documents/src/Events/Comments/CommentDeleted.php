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
use Vaulter\Documents\Data\Snapshots\CommentSnapshot;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Drive\Events\DriveEvent;
use Vaulter\Drive\ValueObjects\Events\ActivityTarget;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * A node comment was permanently deleted (docs/adr/0014-legacy-docvault-strangler.md шаг 1 — no soft-delete). Broadcast
 * on the node's private collaboration channel so open editors drop it live. The model
 * still carries its id/node_id in memory after the delete, so the payload is complete.
 */
#[Api]
final readonly class CommentDeleted extends DriveEvent implements ShouldBroadcast
{
    public CommentSnapshot $comment;

    private NodeComment $commentRow;

    #[Internal]
    public function __construct(
        NodeComment $comment,
        ActorRef $actor,
        string $correlationId,
        DocumentSnapshotMapper $documents,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->commentRow = $comment;
        $this->comment = $documents->comment($comment);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DocumentEventType
    {
        return DocumentEventType::CommentDeleted;
    }

    public function scope(): EventScope
    {
        return self::nodeScope($this->commentRow->node);
    }

    public function subject(): EventSubject
    {
        return new EventSubject(type: 'comment', id: $this->commentRow->getKey());
    }

    public function data(): array
    {
        return [
            'comment_id' => self::id($this->commentRow->getKey()),
            'node_id' => self::id($this->commentRow->node_id),
        ];
    }

    public function activityTarget(): ActivityTarget
    {
        return ActivityTarget::of($this->commentRow->node);
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(app(DocumentsConfig::class)->channelPrefix().'.'.Identity::stringify($this->commentRow->node_id)),
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
            'id' => $this->commentRow->getKey(),
            'node_id' => $this->commentRow->node_id,
        ];
    }
}
