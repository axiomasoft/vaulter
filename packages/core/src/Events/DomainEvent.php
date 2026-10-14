<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\ActivityTarget;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;
use Vaulter\ValueObjects\Events\JournalEffect;

/**
 * One occurrence of a change in Vaulter. Actions hand it to {@see EventRecorder}
 * inside their transaction; the recorder hands it to the event sinks and the outbox with
 * the same eventId and dispatches it to host listeners once the transaction commits.
 *
 * The eventId identifies the occurrence, not the command: two identical renames are
 * two events, while the same event recorded again (a retried job holding the
 * instance it built) is a no-op.
 */
abstract readonly class DomainEvent
{
    public string $eventId;

    public CarbonImmutable $occurredAt;

    public function __construct(
        public ActorRef $actor,
        public string $correlationId,
        ?string $eventId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
        $this->occurredAt = ($occurredAt ?? CarbonImmutable::now())->utc();
    }

    abstract public function type(): EventTypeContract;

    abstract public function scope(): EventScope;

    abstract public function subject(): EventSubject;

    /**
     * The webhook data: ids, enums, counters and flags only, snake_case keys. Names,
     * paths and tokens stay in {@see activityContext()}.
     *
     * @return array<string, mixed>
     */
    abstract public function data(): array;

    /** The node's sequence after the change, for events that change a node. */
    public function sequence(): ?int
    {
        return null;
    }

    /**
     * The activity row's context. The feed hides the sensitive keys from readers who
     * may not share the target.
     *
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return $this->data();
    }

    public function activityTarget(): ?ActivityTarget
    {
        return null;
    }

    public function isWebhookEligible(): bool
    {
        return $this->type()->spec()->external;
    }

    /**
     * The change-journal entry of this occurrence, or null when the type is not
     * journalled. Its operation matches the `journal` of the type's spec.
     */
    public function journal(): ?JournalEffect
    {
        return null;
    }

    protected static function nodeScope(Node $node): EventScope
    {
        return new EventScope(tenantId: $node->tenant_id, driveId: $node->drive_id, profile: $node->profile);
    }

    protected static function nodeSubject(Node $node): EventSubject
    {
        return new EventSubject(type: 'node', id: $node->getKey());
    }

    /**
     * The upsert entry of a node change. With $subtree the entry asks clients to re-read
     * the subtree, which only means something for a folder.
     */
    protected static function nodeUpsert(Node $node, bool $subtree = false, ?int $sequence = null): JournalEffect
    {
        return JournalEffect::upsert(nodeId: $node->getKey(), subtree: $subtree && $node->kind === NodeKind::Folder, nodeSequence: $sequence);
    }

    protected static function id(mixed $value): ?string
    {
        $id = Identity::stringify($value);

        return $id === '' ? null : $id;
    }
}
