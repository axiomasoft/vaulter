<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    expect(app(VaulterConfig::class)->featureEnabled('webhooks'))->toBeTrue();
});

it('stages three distinct occurrences for rename A to B to A to B', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->createFolder('A');

    foreach (['B', 'A', 'B'] as $name) {
        $drive->rename($node->refresh(), $name);
    }

    $rows = Outbox::query()->where('topic', (string) $node->getKey())
        ->where('event', EventType::NodeRenamed->value)->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('event_id')->unique())->toHaveCount(3)
        ->and($rows->pluck('payload.to')->all())->toBe(['B', 'A', 'B']);

    foreach ($rows as $row) {
        expect(Str::isUlid($row->event_id))->toBeTrue()
            ->and(strlen($row->event_id))->toBe(26)
            ->and($row->payload['event_id'])->toBe($row->event_id);
    }
});

it('stages three distinct occurrences for trash restore trash', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->createFolder('Lifecycle');

    $drive->trash($node);
    $drive->restore($node->refresh());
    $drive->trash($node->refresh());

    $rows = Outbox::query()->where('topic', (string) $node->getKey())
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
            ->and($row->payload['event_id'])->toBe($row->event_id);
    }
});

it('stages one row when the same event occurrence is dispatched twice', function (): void {
    $node = DriveTestHelpers::drive()->createFolder('A');
    $event = new NodeRenamed(node: $node, fromName: 'A', toName: 'B');

    event($event);
    event($event);

    $row = Outbox::query()->where('event', EventType::NodeRenamed->value)->sole();

    expect($row->event_id)->toBe($event->eventId)
        ->and($row->payload['event_id'])->toBe($event->eventId);
});
