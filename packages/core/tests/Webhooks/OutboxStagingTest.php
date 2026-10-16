<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Sharing\ShareTestHelpers;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\DomainEvent;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxWriter;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\SinkResult;
use Vaulter\Events\SinkWriter;
use Vaulter\Testing\Fixtures\TestUser;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * The SYNCHRONOUS half of the transactional outbox (§2.11 / D14 / M3b): each recorded
 * domain event stages EXACTLY ONE v_outbox row, idempotent by event_id, whose payload is
 * the thin webhook envelope (ids, enums, counters — no names, secrets or blob bytes).
 */
beforeEach(function (): void {
    // The base suite is open (null driver), so the Drive ops succeed and record events.
    config()->set('vaulter.features.share_links', true);
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

function outboxStagingRecord(DomainEvent $event): void
{
    app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record($event));
}

function outboxStagingActor(): ActorRef
{
    return new ActorRef(type: (new TestUser)->getMorphClass(), id: 7);
}

// ─────────────────────────── one event ⇒ one outbox row ───────────────────────────

it('stages exactly one outbox row when a storage is provisioned', function (): void {
    $storage = DriveTestHelpers::drive()->model();

    $rows = OutboxEvent::query()->where('type', DriveEventType::DriveCreated->value)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->external)->toBeTrue()
        ->and($rows->first()->payload['subject'])->toEqualCanonicalizing(['type' => 'drive', 'id' => (string) $storage->getKey()])
        ->and($rows->first()->payload['data']['root_node_id'])->toBe((string) $storage->root_node_id)
        ->and($rows->first()->payload['actor']['type'])->toBe(ActorRef::SYSTEM_TYPE);
});

it('stages a folder.created row carrying the actor and a thin payload', function (): void {
    $storage = AccessTestHelpers::storage();

    // Clear the provisioning rows so we assert the one we cause below.
    OutboxEvent::query()->delete();

    $folder = AccessTestHelpers::folder($storage, null, 'reports');
    outboxStagingRecord(new FolderCreated(node: $folder, actor: outboxStagingActor(), correlationId: (string) Str::ulid()));

    $row = OutboxEvent::query()->where('type', DriveEventType::FolderCreated->value)->sole();

    expect($row->payload['subject'])->toEqualCanonicalizing(['type' => 'node', 'id' => (string) $folder->getKey()])
        ->and($row->payload['data'])->toBe(['parent_id' => $folder->parent_id === null ? null : (string) $folder->parent_id])
        ->and($row->payload['actor'])->toEqualCanonicalizing(['type' => (new TestUser)->getMorphClass(), 'id' => '7'])
        ->and($row->payload)->not->toHaveKey('name')
        // The envelope id is the occurrence id consumers dedupe on.
        ->and($row->payload['id'])->toBe($row->event_id);
});

// ─────────────────────────── idempotency (event_id) ───────────────────────────

it('is idempotent by event_id: staging the same occurrence again inserts nothing', function (): void {
    $storage = AccessTestHelpers::storage();
    OutboxEvent::query()->delete();
    $folder = AccessTestHelpers::folder($storage, null, 'dup');
    $event = new FolderCreated(node: $folder, actor: outboxStagingActor(), correlationId: (string) Str::ulid());
    $writer = app(OutboxWriter::class);

    [$first, $second] = app(VaulterDatabase::class)->transaction(fn (): array => [
        $writer->append($event, new SinkWriter(app(VaulterDatabase::class))),
        $writer->append($event, new SinkWriter(app(VaulterDatabase::class))),
    ]);

    expect($first)->toBe(SinkResult::Stored)
        ->and($second)->toBe(SinkResult::Replayed)
        ->and(OutboxEvent::query()->where('type', DriveEventType::FolderCreated->value)->count())->toBe(1);
});

it('keeps distinct grant occurrences with identical parameters and deduplicates their replay', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'Granted');
    $grant = AccessTestHelpers::grant(node: $node, role: RoleRegistry::READER);
    $subject = new Subject(type: $grant->subject_type, id: $grant->subject_id);
    OutboxEvent::query()->delete();

    $created = static fn (?string $eventId = null): NodeGrantCreated => new NodeGrantCreated(grant: $grant, actor: outboxStagingActor(), correlationId: 'c', eventId: $eventId);
    $revoked = static fn (?string $eventId = null): NodeGrantRevoked => new NodeGrantRevoked(node: $node, subject: $subject, revoked: 1, actor: outboxStagingActor(), correlationId: 'c', eventId: $eventId);

    foreach ([$created, $created, $revoked, $revoked] as $make) {
        $event = $make();
        outboxStagingRecord($event);
        // A rebuilt event of the same occurrence is a replay, not a new row.
        outboxStagingRecord($make($event->eventId));

        $row = OutboxEvent::query()->where('event_id', $event->eventId)->sole();
        expect(Str::isUlid($row->event_id))->toBeTrue()
            ->and($row->payload['id'])->toBe($event->eventId);
    }

    expect(OutboxEvent::query()->where('type', DriveEventType::GrantCreated->value)->count())->toBe(2)
        ->and(OutboxEvent::query()->where('type', DriveEventType::GrantRevoked->value)->count())->toBe(2)
        ->and(OutboxEvent::query()->distinct()->count('event_id'))->toBe(4);
});

it('never serializes a share secret, token or token prefix', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'public');
    $share = ShareTestHelpers::share($node, token: 'tok-outbox');
    OutboxEvent::query()->delete();

    outboxStagingRecord(new NodeShareCreated(share: $share, actor: outboxStagingActor(), correlationId: (string) Str::ulid()));

    $payload = OutboxEvent::query()->where('type', DriveEventType::ShareCreated->value)->sole()->payload;

    expect(array_keys($payload['data']))->toEqualCanonicalizing(['node_id', 'role', 'role_version', 'audience', 'scope', 'expires_at'])
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('tok-outbox')
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain((string) $share->token_prefix);
});
