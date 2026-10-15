<?php

declare(strict_types=1);

namespace Vaulter\Data\Activity;

use Vaulter\Attributes\Internal;
use Vaulter\Events\EventTypeContract;

/**
 * The resolved, ready-to-persist shape of ONE activity row. The ActivityRecorder maps a domain event into this command (occurrence
 * id, verb, actor, target, storage, context + request meta); the
 * ActivityStoreRepository persists it verbatim as a single append-only row.
 *
 * Everything is already a primitive/morph here — no models — so the row is a pure,
 * immutable snapshot independent of the live entities (a force-deleted node's id
 * still records cleanly). There is exactly ONE row per event: recipients are
 * resolved at READ by scoping the feed, never fanned out per affected user.
 *
 * @property array<string, mixed>|null $context
 */
#[Internal]
final readonly class RecordActivityCommand
{
    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public string $eventId,
        public EventTypeContract $verb,
        public int|string|null $tenantId = null,
        public int|string|null $storageId = null,
        public ?string $actorType = null,
        public int|string|null $actorId = null,
        public ?string $targetType = null,
        public int|string|null $targetId = null,
        public ?array $context = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {}
}
