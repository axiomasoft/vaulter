<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Nodes\FolderCreated;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxEnvelope;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\TestCase;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;
use Vaulter\ValueObjects\Access\ActorRef;
use Vaulter\Webhooks\WebhookFanoutConsumer;

/**
 * The write half of the outbox (D29, I10): every event lands in v_outbox in the transaction of its operation,
 * whatever modules are active; a pending delivery row is written for each consumer of the sealed registry that
 * subscribes to the type; a rollback leaves neither; a replayed occurrence writes nothing.
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

function outboxWriteFolder(): FolderCreated
{
    $node = AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'Written');

    return new FolderCreated(node: $node, actor: new ActorRef(type: 'user', id: 7), correlationId: (string) Str::ulid());
}

function outboxWriteRecord(object $event): void
{
    app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record($event));
}

it('writes the event with a delivery row for each subscribed consumer', function (): void {
    $event = outboxWriteFolder();
    outboxWriteRecord($event);

    $row = OutboxEvent::query()->where('event_id', $event->eventId)->sole();
    expect($row->type)->toBe('folder.created')
        ->and($row->external)->toBeTrue()
        ->and($row->scope_kind)->toBe(OutboxEnvelope::SCOPE_DRIVE)
        ->and($row->scope_id)->toBe((string) $event->scope()->driveId)
        ->and($row->aggregate_type)->toBe('node')
        ->and($row->payload['id'])->toBe($event->eventId)
        ->and($row->outbox_seq)->toBeGreaterThan(0)
        ->and(WebhookTestHelpers::outboxDelivery($event->eventId))->toMatchArray([
            'state' => 'pending',
            'attempts' => 0,
            'outbox_seq' => $row->outbox_seq,
        ]);
});

it('writes the outbox whatever modules are active: without the webhooks module only the event', function (): void {
    WebhookTestHelpers::switchModule($this->app, false);
    $event = outboxWriteFolder();
    outboxWriteRecord($event);

    expect(OutboxEvent::query()->where('event_id', $event->eventId)->exists())->toBeTrue()
        ->and(WebhookTestHelpers::outboxDelivery($event->eventId))->toBeNull();
});

it('leaves neither the event nor its delivery rows when the operation rolls back', function (): void {
    $event = outboxWriteFolder();

    expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($event): void {
        app(EventRecorder::class)->record($event);

        throw new RuntimeException('operation failed');
    }))->toThrow(RuntimeException::class, 'operation failed');

    expect(OutboxEvent::query()->where('event_id', $event->eventId)->exists())->toBeFalse()
        ->and(WebhookTestHelpers::outboxDelivery($event->eventId))->toBeNull();
});

it('writes nothing for a replayed occurrence', function (): void {
    $event = outboxWriteFolder();
    outboxWriteRecord($event);
    $replay = new FolderCreated(node: AccessTestHelpers::folder(AccessTestHelpers::storage(), null, 'Other'), actor: new ActorRef(type: 'user', id: 7), correlationId: (string) Str::ulid(), eventId: $event->eventId);
    outboxWriteRecord($replay);

    expect(OutboxEvent::query()->where('event_id', $event->eventId)->count())->toBe(1)
        ->and(WebhookTestHelpers::outboxDelivery($event->eventId, WebhookFanoutConsumer::ID))->not->toBeNull();
});

it('orders the outbox by a monotonic seq', function (): void {
    $first = outboxWriteFolder();
    $second = outboxWriteFolder();
    outboxWriteRecord($first);
    outboxWriteRecord($second);

    $seqs = OutboxEvent::query()->whereIn('event_id', [$first->eventId, $second->eventId])->orderBy('outbox_seq')->pluck('event_id')->all();
    expect($seqs)->toBe([$first->eventId, $second->eventId]);
});
