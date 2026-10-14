<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Drive\DriveTestHelpers;

/**
 * Changing a node's properties is a node.updated event (PLAN2.D41); the values are
 * host data, so only the fact of the change is published.
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

beforeEach(function (): void {
    $this->drive = DriveTestHelpers::drive();
    $this->actor = $this->drive->actingAs(DriveTestHelpers::actor($this->drive, 7));
    $this->node = $this->actor->folders()->create('props');
});

it('records node.updated with the new node sequence and no property values', function (): void {
    $before = DriveTestHelpers::model($this->node)->sequence;

    $this->actor->nodes()->setProperties($this->node, 'acme', ['label' => 'confidential-value']);

    $outbox = Outbox::query()->where('event', DriveEventType::NodeUpdated->value)->sole();
    $activity = Activity::query()->where('verb', DriveEventType::NodeUpdated->value)->sole();

    expect($outbox->payload['data'])->toBe(['changed' => ['properties']])
        ->and($outbox->payload['sequence'])->toBe($before + 1)
        ->and($outbox->payload['subject'])->toBe(['type' => 'node', 'id' => (string) $this->node->id])
        ->and($activity->event_id)->toBe($outbox->event_id)
        ->and(json_encode([$outbox->payload, $activity->context]))->not->toContain('confidential-value');
});

it('records nothing for a patch that changes nothing', function (): void {
    $this->actor->nodes()->setProperties($this->node, 'acme', ['label' => 'same']);
    $this->actor->nodes()->setProperties($this->node, 'acme', ['label' => 'same']);

    expect(Outbox::query()->where('event', DriveEventType::NodeUpdated->value)->count())->toBe(1);
});
