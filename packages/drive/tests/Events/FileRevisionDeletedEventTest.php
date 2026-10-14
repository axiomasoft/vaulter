<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Events\EventConformance;

/**
 * Deleting a non-current revision is audited and staged for webhooks like every other
 * lifecycle event (PLAN2.D48).
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

it('records file.revision_deleted in the activity feed and the outbox', function (): void {
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $actor = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $file = $actor->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'a.txt'));
    $actor->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('v2-bytes'), 'a.txt'));
    $first = DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->where('number', 1)->sole();

    $actor->files()->deleteRevision($file, $first->getKey());

    $outbox = Outbox::query()->where('event', EventType::FileRevisionDeleted->value)->sole();
    $activity = Activity::query()->where('verb', EventType::FileRevisionDeleted->value)->sole();

    expect($outbox->payload['data'])->toBe(['revision_id' => (string) $first->getKey(), 'number' => 1])
        ->and($activity->event_id)->toBe($outbox->event_id)
        ->and($activity->context['size_bytes'])->toBe(2)
        ->and($outbox->payload['sequence'])->toBeInt();
    EventConformance::assertEnvelope(EventType::FileRevisionDeleted, $outbox->payload);
});
