<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\NodeCreated;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Webhooks\WebhookEvent;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;
use Vaulter\Webhooks\OutboxRecorder;
use Vaulter\Webhooks\RecordsOutbox;

/**
 * The SYNCHRONOUS half of the transactional outbox (§2.11 / D14 / M3b): each fanned-out
 * Drive domain event stages EXACTLY ONE v_outbox row, idempotent by event_id, with a
 * slim payload (no secrets/blob bytes). Staging is gated on the 'reliability' feature
 * and is resilient — a recorder failure never breaks the originating action.
 */
beforeEach(function (): void {
    // The base suite is open (null driver), so the Drive ops succeed and fire events.
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

// ─────────────────────────── one event ⇒ one outbox row ───────────────────────────

it('stages exactly one outbox row when a storage is provisioned', function (): void {
    DriveTestHelpers::drive()->storage();

    $rows = Outbox::query()->where('event', WebhookEvent::StorageCreated->value)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status)->toBe('pending')
        ->and($rows->first()->payload['name'])->not->toBeNull();
});

it('stages a node.created row carrying the actor and a slim payload', function (): void {
    $storage = AccessTestHelpers::storage();

    // Clear the provisioning rows so we assert the one we cause below.
    Outbox::query()->delete();

    $folder = AccessTestHelpers::folder($storage, null, 'reports');
    event(new NodeCreated(node: $folder, actorId: 7));

    $rows = Outbox::query()->where('event', WebhookEvent::NodeCreated->value)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->payload['node_id'])->toEqual($folder->getKey())
        ->and($rows->first()->payload['name'])->toBe('reports')
        ->and($rows->first()->payload['actor_id'])->toEqual(7)
        // The event_id is mirrored into the payload so consumers dedupe on it.
        ->and($rows->first()->payload['event_id'])->toBe($rows->first()->event_id);
});

// ─────────────────────────── idempotency (event_id) ───────────────────────────

it('is idempotent by event_id: re-staging the same occurrence stages no duplicate', function (): void {
    $storage = AccessTestHelpers::storage();
    Outbox::query()->delete();

    $folder = AccessTestHelpers::folder($storage, null, 'dup');

    // Two stagings under the SAME event_id key → the second is a no-op → one row. This
    // exercises the core OutboxRecorder::stage() idempotency seam directly (the seam the
    // vaulter-drive subscriber maps its node events through).
    $recorder = app(OutboxRecorder::class);
    $eventId = (string) Str::ulid();

    $stage = fn (): ?Outbox => $recorder->stage(
        event: WebhookEvent::NodeCreated,
        eventId: $eventId,
        panel: $folder->panel,
        topic: (string) $folder->getKey(),
        payload: ['node_id' => $folder->getKey(), 'name' => 'dup'],
        actorId: 7,
    );

    $first = $stage();
    $second = $stage();

    expect($first)->toBeInstanceOf(Outbox::class)
        // The second staging is a no-op (returns null) — idempotent by event_id.
        ->and($second)->toBeNull()
        ->and(Outbox::query()->where('event', WebhookEvent::NodeCreated->value)->count())->toBe(1);
});

it('keeps distinct grant occurrences with identical parameters and deduplicates their replay', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'Granted');
    $grant = AccessTestHelpers::grant(node: $node, level: GrantLevel::Reader);
    $subject = new Subject(type: $grant->subject_type, id: $grant->subject_id);
    Outbox::query()->delete();

    foreach ([
        new NodeGrantCreated(grant: $grant, actorId: 7),
        new NodeGrantCreated(grant: $grant, actorId: 7),
        new NodeGrantRevoked(node: $node, subject: $subject, revoked: 1, actorId: 7),
        new NodeGrantRevoked(node: $node, subject: $subject, revoked: 1, actorId: 7),
    ] as $event) {
        event($event);
        event($event);

        $row = Outbox::query()->where('event_id', $event->eventId)->sole();
        expect(Str::isUlid($row->event_id))->toBeTrue()
            ->and($row->payload['event_id'])->toBe($event->eventId);
    }

    expect(Outbox::query()->where('event', WebhookEvent::GrantCreated->value)->count())->toBe(2)
        ->and(Outbox::query()->where('event', WebhookEvent::GrantRevoked->value)->count())->toBe(2)
        ->and(Outbox::query()->distinct()->count('event_id'))->toBe(4);
});

it('retains an explicit occurrence id across reconstructed events', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'Replay');
    Outbox::query()->delete();
    $eventId = (string) Str::ulid();

    event(new NodeCreated(node: $node, actorId: 7, eventId: $eventId));
    event(new NodeCreated(node: $node, actorId: 7, eventId: $eventId));

    $row = Outbox::query()->sole();
    expect($row->event_id)->toBe($eventId)
        ->and($row->payload['event_id'])->toBe($eventId);
});

it('never serializes a share secret — only the non-secret token_prefix travels', function (): void {
    // This is a payload-shape guard: the recorder must not leak token_hash/secret.
    $storage = AccessTestHelpers::storage();
    Outbox::query()->delete();

    // Drive a node and assert the staged payload has no secret-shaped keys anywhere.
    $folder = AccessTestHelpers::folder($storage, null, 'safe');
    event(new NodeCreated(node: $folder, actorId: 7));

    $payload = Outbox::query()->where('event', WebhookEvent::NodeCreated->value)->firstOrFail()->payload;

    expect(array_keys($payload))->not->toContain('signing_secret')
        ->and(array_keys($payload))->not->toContain('token_hash')
        ->and(array_keys($payload))->not->toContain('password');
});

// ─────────────────────────── resilience ───────────────────────────

it('swallows + logs a staging failure so the originating action is unaffected', function (): void {
    Log::spy();

    // A recorder whose recording blows up; the subscriber must swallow + log it. The
    // core RecordsOutbox handles the kernel (grant/share) events; the grant is never
    // inspected because the mocked recorder throws before touching it.
    $recorder = Mockery::mock(OutboxRecorder::class);
    $recorder->shouldReceive('recordGrantCreated')->andThrow(new RuntimeException('boom'));

    $subscriber = new RecordsOutbox($recorder, app(VaulterDatabase::class));

    // Must NOT throw — the failure is logged and swallowed.
    $subscriber->handleGrantCreated(new NodeGrantCreated(grant: new NodeGrant, actorId: 7));

    Log::shouldHaveReceived('warning')->once();
});
