<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Vaulter\Attributes\Api;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

/**
 * One occurrence of a change in Vaulter. Actions hand it to {@see EventRecorder}
 * inside their transaction; the recorder hands it to the event sinks and the outbox with
 * the same eventId and dispatches it to host listeners once the transaction commits.
 *
 * The eventId identifies the occurrence, not the command: two identical renames are
 * two events, while the same event recorded again (a retried job holding the
 * instance it built) is a no-op.
 */
#[Api]
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
     * The context of the occurrence's audit trail (the drive's activity row). The feed hides the sensitive keys from
     * readers who may not share the target.
     *
     * @return array<string, mixed>
     */
    public function activityContext(): array
    {
        return $this->data();
    }

    /**
     * Whether this occurrence may leave the process through the outbox consumers: the `external` flag of
     * the type, which an event narrows per occurrence (a document save without a checkpoint stays inside).
     */
    public function isExternal(): bool
    {
        return $this->type()->spec()->external;
    }

    protected static function id(mixed $value): ?string
    {
        $id = Identity::stringify($value);

        return $id === '' ? null : $id;
    }
}
