<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use stdClass;
use Vaulter\Events\DomainEvent;
use Vaulter\Support\Identity;

/**
 * Builds the webhook body of an event. The result is stored as the outbox payload
 * and copied unchanged into every delivery, so the bytes a receiver verifies are the
 * bytes Vaulter built once.
 *
 * The envelope is thin: data carries ids, enums and counters only. A receiver that
 * needs names or content fetches the subject with its own credentials, and the
 * activity feed keeps the human-readable context.
 */
final class EventEnvelope
{
    /** Keys that must never appear anywhere in an envelope. */
    public const array FORBIDDEN_KEYS = [
        'name', 'from_name', 'to_name', 'path', 'mime', 'mime_type', 'content', 'token', 'token_hash',
        'token_prefix', 'secret', 'password', 'ip', 'user_agent', 'request_id', 'storage_path',
    ];

    /** @return array<string, mixed> */
    public static function fromEvent(DomainEvent $event, ?int $changeSeq = null): array
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
}
