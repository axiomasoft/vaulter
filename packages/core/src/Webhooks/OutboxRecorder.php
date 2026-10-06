<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\Sharing\NodeShareRevoked;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Support\Identity;

/**
 * The SYNCHRONOUS half of the transactional outbox (master plan §2.11 / D14 / M3b),
 * ported from chatom's WebhookDispatcher. It maps a known Vaulter domain event into a
 * SLIM webhook payload and durably stages it as ONE v_outbox row — idempotent by
 * event_id, so a re-fired event never stages a duplicate. The actual HTTP delivery
 * (retries, backoff, DLQ, signed POST) is the relay's job.
 *
 * Kernel domains (Access grants, Sharing links) map their own events here; the Drive
 * behaviour package (P2 #5 / ADR-0015) maps its file/folder/upload events through the
 * PUBLIC {@see stage()} seam, so core no longer references any Vaulter\Drive\Events\*
 * class while the outbox payloads retain their domain fields.
 *
 * Slim payloads (HARDENING): only ids, names, sizes and enum values travel — never
 * blob bytes or secrets (a share's token_hash/secret is never serialized; only the
 * non-secret token_prefix). The event_id is STABLE and event-derived so the relay
 * and downstream consumers can dedupe at-least-once delivery on it.
 *
 * Thin side-effect service (store-only): it persists an outbox row and nothing else.
 * It makes no business decisions and never accepts a Request. Not final — a host may
 * subclass it to enrich payloads, and the subscriber's resilience is test-mockable.
 */
class OutboxRecorder
{
    public function __construct(
        private readonly VaulterConfig $vaulterConfig,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function recordGrantCreated(NodeGrantCreated $event): ?Outbox
    {
        $node = $event->grant->node;

        return $this->stage(
            event: EventType::GrantCreated,
            eventId: $event->eventId,
            panel: $node->profile,
            topic: $this->id($node->getKey()),
            payload: [
                'grant_id' => $this->id($event->grant->getKey()),
                'node_id' => $this->id($node->getKey()),
                'drive_id' => $this->id($node->drive_id),
                'subject_type' => $event->grant->subject_type,
                'subject_id' => $this->id($event->grant->subject_id),
                'level' => $event->grant->level->value,
                'effect' => $event->grant->effect->value,
                'scope' => $event->grant->scope->value,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordGrantRevoked(NodeGrantRevoked $event): ?Outbox
    {
        return $this->stage(
            event: EventType::GrantRevoked,
            eventId: $event->eventId,
            panel: $event->node->profile,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'drive_id' => $this->id($event->node->drive_id),
                'subject_type' => $event->subject->type,
                'subject_id' => $this->id($event->subject->id),
                'revoked' => $event->revoked,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordShareCreated(NodeShareCreated $event): ?Outbox
    {
        $node = $event->share->node;

        return $this->stage(
            event: EventType::ShareCreated,
            eventId: $event->eventId,
            panel: $node->profile,
            topic: $this->id($node->getKey()),
            // The raw token / token_hash is NEVER serialized — only the non-secret
            // token_prefix travels (slim-payload hardening).
            payload: [
                'share_id' => $this->id($event->share->getKey()),
                'node_id' => $this->id($node->getKey()),
                'drive_id' => $this->id($node->drive_id),
                'token_prefix' => $event->share->token_prefix,
                'level' => $event->share->level->value,
                'scope' => $event->share->scope->value,
                'permissions' => $event->share->permissions,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordShareRevoked(NodeShareRevoked $event): ?Outbox
    {
        $node = $event->share->node;

        return $this->stage(
            event: EventType::ShareRevoked,
            eventId: $event->eventId,
            panel: $node->profile,
            topic: $this->id($node->getKey()),
            payload: [
                'share_id' => $this->id($event->share->getKey()),
                'node_id' => $this->id($node->getKey()),
                'drive_id' => $this->id($node->drive_id),
                'token_prefix' => $event->share->token_prefix,
            ],
            actorId: $event->actorId,
        );
    }

    /**
     * Stage ONE outbox row for an event — the PUBLIC seam the kernel handlers above
     * and the vaulter-drive subscriber both map through. Idempotent by event_id: when
     * a row for this occurrence id already exists (a retried send, a re-fired event), this is a
     * no-op and returns null, so the relay never fans the same event out twice.
     *
     * @param array<string, mixed> $payload
     */
    public function stage(
        EventType $event,
        string $eventId,
        string $panel,
        ?string $topic,
        array $payload,
        int|string|null $actorId,
    ): ?Outbox {
        /** @var class-string<Outbox> $outboxClass */
        $outboxClass = $this->vaulterConfig->model('outbox');

        if ($outboxClass::query()->where('event_id', $eventId)->exists()) {
            return null;
        }

        return $this->metrics->timing(
            name: 'vaulter_outbox_stage_duration_seconds',
            operation: function () use ($outboxClass, $eventId, $panel, $topic, $event, $payload, $actorId): Outbox {
                // The actor id rides inside the payload so consumers see who caused the event
                // without a separate column on the wire.
                $payload['event_id'] = $eventId;
                $payload['actor_id'] = Identity::key($actorId);

                // forceFill (P1.4/F-P1.8-02): Outbox is intentionally fully guarded — this
                // is the outbox's own trusted staging seam, never raw request input.
                /** @var Outbox $outbox */
                $outbox = new $outboxClass;
                $outbox->forceFill([
                    'event_id' => $eventId,
                    'profile' => $panel,
                    'topic' => $topic,
                    'event' => $event->value,
                    'payload' => $payload,
                    'available_at' => now(),
                    'status' => 'pending',
                    'attempts' => 0,
                ]);
                $outbox->save();

                return $outbox;
            },
        );
    }

    /**
     * Stringify an id for a slim payload/topic, mapping the empty string to null. The
     * PUBLIC helper the vaulter-drive subscriber reuses so its payload ids match the
     * kernel handlers' exactly.
     */
    public function id(mixed $value): ?string
    {
        $string = Identity::stringify($value);

        return $string === '' ? null : $string;
    }
}
