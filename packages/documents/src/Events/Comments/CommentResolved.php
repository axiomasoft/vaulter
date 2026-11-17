<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Comments;

use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsOptions;
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
 * A node comment thread was marked resolved. Broadcast on the node's
 * private collaboration channel so open editors reflect the resolution live. The
 * actor who resolved it is also stamped as resolved_by on the row.
 */
#[Api]
final readonly class CommentResolved extends DriveEvent implements ShouldBroadcast
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
        return DocumentEventType::CommentResolved;
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
            new PrivateChannel(app(DocumentsOptions::class)->broadcast->channelPrefix.'.'.Identity::stringify($this->commentRow->node_id)),
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
            'id' => $this->commentRow->getKey(),
            'node_id' => $this->commentRow->node_id,
            'resolved_at' => $this->commentRow->resolved_at,
            'resolved_by' => $this->commentRow->resolved_by,
        ];
    }
}
