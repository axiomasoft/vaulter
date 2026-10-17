<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Events\EventConformance;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Testing\Support\CanonicalJson;

/**
 * Deleting a non-current revision is audited and staged for webhooks like every other
 * lifecycle event (PLAN2.D48).
 */
uses(TestCase::class, RefreshDatabase::class);

it('records file.revision_deleted in the activity feed and the outbox', function (): void {
    Storage::fake('local');
    $drive = DriveTestHelpers::drive();
    $actor = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $file = $actor->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'a.txt'));
    $actor->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('v2-bytes'), 'a.txt'));
    $first = DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->where('number', 1)->sole();

    $actor->files()->deleteRevision($file, $first->getKey());

    $outbox = OutboxEvent::query()->where('type', DriveEventType::FileRevisionDeleted->value)->sole();
    $activity = Activity::query()->where('verb', DriveEventType::FileRevisionDeleted->value)->sole();

    expect(CanonicalJson::of($outbox->payload['data']))->toBe(CanonicalJson::of(['revision_id' => (string) $first->getKey(), 'number' => 1]))
        ->and($activity->event_id)->toBe($outbox->event_id)
        ->and($activity->context['size_bytes'])->toBe(2)
        ->and($outbox->payload['sequence'])->toBeInt();
    EventConformance::assertEnvelope(DriveEventType::FileRevisionDeleted, $outbox->payload);
});
