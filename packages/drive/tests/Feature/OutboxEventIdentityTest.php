<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Enums\Events\EventType;
use Vaulter\Events\EventRecorder;
use Vaulter\Kernel;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\ValueObjects\Access\Actor;

uses(EnablesWebhooksModule::class);

beforeEach(function (): void {
    expect(app(Kernel::class)->isActive('webhooks'))->toBeTrue();
});

it('stages three distinct occurrences for rename A to B to A to B', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('A');

    foreach (['B', 'A', 'B'] as $name) {
        $drive->nodes()->rename($node, $name);
    }

    $rows = Outbox::query()->where('topic', (string) $node->id)
        ->where('event', EventType::NodeRenamed->value)->orderBy('id')->get();

    $names = Activity::query()->whereIn('event_id', $rows->pluck('event_id'))->orderBy('id')->get()->pluck('context.to')->all();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('event_id')->unique())->toHaveCount(3)
        // Names stay in the activity feed; the webhook envelope carries ids only.
        ->and($names)->toBe(['B', 'A', 'B']);

    foreach ($rows as $row) {
        expect(Str::isUlid($row->event_id))->toBeTrue()
            ->and(strlen($row->event_id))->toBe(26)
            ->and($row->payload['id'])->toBe($row->event_id);
    }
});

it('stages three distinct occurrences for trash restore trash', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('Lifecycle');

    $drive->nodes()->trash($node);
    $drive->trash()->restore($node);
    $drive->nodes()->trash($node);

    $rows = Outbox::query()->where('topic', (string) $node->id)
        ->whereIn('event', [EventType::NodeTrashed->value, EventType::NodeRestored->value])
        ->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('event_id')->unique())->toHaveCount(3)
        ->and($rows->pluck('event')->all())->toBe([
            EventType::NodeTrashed->value,
            EventType::NodeRestored->value,
            EventType::NodeTrashed->value,
        ]);

    foreach ($rows as $row) {
        expect(Str::isUlid($row->event_id))->toBeTrue()
            ->and($row->payload['id'])->toBe($row->event_id);
    }
});

it('stages one row when the same event occurrence is recorded twice', function (): void {
    $node = DriveTestHelpers::model(DriveTestHelpers::drive()->folders()->create('A'));
    $eventId = (string) Str::ulid();
    $make = static fn (): NodeRenamed => new NodeRenamed(node: $node, fromName: 'A', toName: 'B', actor: Actor::system('replay')->ref(), correlationId: 'c', eventId: $eventId);

    app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record($make()));
    app(VaulterDatabase::class)->transaction(fn () => app(EventRecorder::class)->record($make()));

    $row = Outbox::query()->where('event', EventType::NodeRenamed->value)->sole();

    expect($row->event_id)->toBe($eventId)
        ->and($row->payload['id'])->toBe($eventId);
});
