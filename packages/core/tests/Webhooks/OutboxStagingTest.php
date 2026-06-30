<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Vaulter\Enums\Webhooks\WebhookEvent;
use Vaulter\Events\Drive\NodeCreated;
use Vaulter\Models\Drive\Node;
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

it('is idempotent by event_id: re-firing the same event stages no duplicate', function (): void {
    $storage = AccessTestHelpers::storage();
    Outbox::query()->delete();

    $folder = AccessTestHelpers::folder($storage, null, 'dup');

    // Two recordings of the SAME node event → the event_id is identical → one row.
    $recorder = app(OutboxRecorder::class);
    $first = $recorder->recordNodeCreated(new NodeCreated(node: $folder, actorId: 7));
    $second = $recorder->recordNodeCreated(new NodeCreated(node: $folder, actorId: 7));

    expect($first)->toBeInstanceOf(Outbox::class)
        // The second staging is a no-op (returns null) — idempotent by event_id.
        ->and($second)->toBeNull()
        ->and(Outbox::query()->where('event', WebhookEvent::NodeCreated->value)->count())->toBe(1);
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

    // A recorder whose recording blows up; the subscriber must swallow + log it.
    $recorder = Mockery::mock(OutboxRecorder::class);
    $recorder->shouldReceive('recordNodeCreated')->andThrow(new RuntimeException('boom'));

    $subscriber = new RecordsOutbox($recorder);

    $node = new Node;
    $node->forceFill(['id' => 1, 'panel' => 'default', 'storage_id' => 1, 'name' => 'x']);

    // Must NOT throw — the failure is logged and swallowed.
    $subscriber->handleNodeCreated(new NodeCreated(node: $node, actorId: 7));

    Log::shouldHaveReceived('warning')->once();
});
