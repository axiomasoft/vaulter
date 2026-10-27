<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

use Carbon\CarbonImmutable;
use stdClass;
use Vaulter\Attributes\Internal;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;

/**
 * One outbox event as an outbox consumer receives it (D29): the neutral columns of the row — seq, type, the
 * `external` flag, tenant, scope and aggregate — and the payload, built once by {@see body()} when the event
 * was written. The payload is the webhook body: a consumer that forwards it sends the bytes Vaulter built.
 *
 * The body is thin: data carries ids, enums and counters only. A receiver that needs names or content fetches
 * the subject with its own credentials, and the activity feed keeps the human-readable context. There is no
 * ordering between events; aggregate_type/aggregate_id/sequence let a consumer order per aggregate, and
 * delivery is at least once, deduplicated by event_id.
 */
#[Spi(SpiKind::Call)]
final readonly class OutboxEnvelope
{
    /** Keys that must never appear anywhere in a payload. */
    public const array FORBIDDEN_KEYS = [
        'name', 'from_name', 'to_name', 'path', 'mime', 'mime_type', 'content', 'token', 'token_hash',
        'token_prefix', 'secret', 'password', 'ip', 'user_agent', 'request_id', 'storage_path',
    ];

    /** The scope of an event bound to a drive. */
    public const string SCOPE_DRIVE = 'drive';

    /** The scope of an event bound to no drive: only its tenant. */
    public const string SCOPE_TENANT = 'tenant';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public int $outboxSeq,
        public string $type,
        public bool $external,
        public ?string $tenantId,
        public string $scopeKind,
        public ?string $scopeId,
        public ?string $aggregateType,
        public ?string $aggregateId,
        public ?int $sequence,
        public CarbonImmutable $createdAt,
        public array $payload,
    ) {}

    /**
     * The payload of `$event`: the webhook body (identity `webhook-envelope`), with the change-journal seq the
     * drive's journal sink shared.
     *
     * @return array<string, mixed>
     */
    public static function body(DomainEvent $event, ?int $changeSeq = null): array
    {
        $scope = $event->scope();
        $subject = $event->subject();

        return [
            'id' => $event->eventId,
            'type' => $event->type()->value,
            'version' => 1,
            'source' => 'vaulter',
            'time' => $event->occurredAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'tenant_id' => $scope->tenantId,
            'drive_id' => $scope->driveId,
            'subject' => ['type' => $subject->type, 'id' => $subject->id],
            'sequence' => $event->sequence(),
            // A system actor's reason is audit detail; it stays in the activity row.
            'actor' => [
                'type' => $event->actor->type,
                'id' => $event->actor->id === null ? null : Identity::stringify($event->actor->id),
            ],
            'correlation_id' => $event->correlationId,
            'changes' => $changeSeq === null ? null : ['seq' => $changeSeq],
            // An empty array would encode as [] where receivers expect an object.
            'data' => $event->data() === [] ? new stdClass : $event->data(),
        ];
    }

    #[Internal]
    public static function fromModel(Models\OutboxEvent $row): self
    {
        return new self(
            eventId: $row->event_id,
            outboxSeq: (int) $row->outbox_seq,
            type: $row->type,
            external: (bool) $row->external,
            tenantId: $row->tenant_id === null ? null : Identity::stringify($row->tenant_id),
            scopeKind: $row->scope_kind,
            scopeId: $row->scope_id,
            aggregateType: $row->aggregate_type,
            aggregateId: $row->aggregate_id,
            sequence: $row->sequence,
            createdAt: CarbonImmutable::instance($row->created_at),
            payload: $row->payload,
        );
    }
}
