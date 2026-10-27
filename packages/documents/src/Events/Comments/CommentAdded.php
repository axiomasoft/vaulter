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
 * A comment was added to a node (docs/adr/0014-legacy-docvault-strangler.md шаг 1). Broadcast on the node's private
 * collaboration channel (channel_prefix.{node_id}) — the SAME room open editors join
 * — so a live thread updates without a poll. Recorded inside the Action's unit
 * of work and dispatched after the OUTERMOST commit: a rollback sends nothing.
 */
#[Api]
final readonly class CommentAdded extends DriveEvent implements ShouldBroadcast
{
    public CommentSnapshot $comment;

    private NodeComment $commentRow;

    #[Internal]
    public function __construct(
        NodeComment $comment,
        ActorRef $actor,
        string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        // The public facts are captured here, in the transaction that raised the event; the hooks keep the row.
        $this->commentRow = $comment;
        $this->comment = app(DocumentSnapshotMapper::class)->comment($comment);
        parent::__construct(actor: $actor, correlationId: $correlationId, eventId: $eventId, occurredAt: $occurredAt);
    }

    public function type(): DocumentEventType
    {
        return DocumentEventType::CommentAdded;
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
        return 'node-comment.added';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->commentRow->getKey(),
            'node_id' => $this->commentRow->node_id,
            'parent_id' => $this->commentRow->parent_id,
            'body' => $this->commentRow->body,
            'created_at' => $this->commentRow->created_at,
        ];
    }
}
