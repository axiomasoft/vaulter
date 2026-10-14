<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Events\EventType;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Operations\DriveContext;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * Every drive event is recorded by the EventRecorder: one activity row and, for webhook
 * types, one outbox row with the same event id. Commands are distinct occurrences even
 * when they repeat a state (V06).
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

/** @return list<string> the event types whose rows do not pair up */
function driveEventsUnpaired(): array
{
    $unpaired = [];
    foreach (Outbox::query()->get() as $outbox) {
        $type = EventType::from($outbox->event);
        if ($type->isActivity() && !Activity::query()->where('event_id', $outbox->event_id)->where('verb', $outbox->event)->exists()) {
            $unpaired[] = $outbox->event;
        }
    }
    foreach (Activity::query()->get() as $activity) {
        $type = $activity->verb;
        if ($activity->event_id === null || ($type->isWebhook() && !Outbox::query()->where('event_id', $activity->event_id)->exists())) {
            $unpaired[] = $type->value;
        }
    }

    return $unpaired;
}

it('records every drive event in both sinks under one event id', function (): void {
    $drive = DriveTestHelpers::drive();
    $actor = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $a = $actor->folders()->create('A');
    $b = $actor->folders()->create('B');
    $file = $actor->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'doc.txt'), new UploadOptions(parent: $a));
    $actor->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('v2-bytes'), 'doc.txt'));
    $first = DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->where('number', 1)->value('id');
    $actor->files()->deleteRevision($file, $first);
    $actor->nodes()->rename($b, 'C');
    $actor->nodes()->move($b, $a);
    $actor->nodes()->copy($b, $drive->root());
    $actor->nodes()->trash($b);
    $actor->trash()->restore($b);
    $actor->nodes()->trash($b);
    $actor->trash()->purge($b);
    $drive->archive();
    $drive->unarchive();

    $types = array_values(array_unique([...Activity::query()->toBase()->pluck('verb')->all(), ...Outbox::query()->toBase()->pluck('event')->all()]));

    expect($types)->toEqualCanonicalizing([
        'drive.created', 'folder.created', 'file.uploaded', 'file.revision_added', 'file.revision_deleted',
        'node.renamed', 'node.moved', 'node.copied', 'node.trashed', 'node.restored', 'node.purged',
        'drive.archived', 'drive.unarchived',
    ])->and(driveEventsUnpaired())->toBe([]);
});

it('records drive.deleted when the purge of a deleted drive finishes', function (): void {
    $drive = DriveTestHelpers::drive();
    $id = $drive->model()->getKey();
    $drive->delete();

    while (!app(PurgeDriveStepAction::class)->execute($id)) {
        // One batch per step; a fresh drive finishes in the first.
    }

    $activity = Activity::query()->where('verb', EventType::DriveDeleted->value)->sole();
    $outbox = Outbox::query()->where('event', EventType::DriveDeleted->value)->sole();

    expect($activity->event_id)->toBe($outbox->event_id)
        ->and($outbox->payload['subject'])->toBe(['type' => 'drive', 'id' => (string) $id]);
});

it('audits upload sessions without staging webhooks', function (): void {
    $drive = DriveTestHelpers::drive();
    $context = DriveContext::system(drive: $drive->model(), reason: 'test upload');
    $session = app(UploadSessionManager::class)->initiate($context, new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetName: 'big.bin',
        totalSizeBytes: 4,
    ));
    app(UploadSessionManager::class)->abort($session);

    expect(Activity::query()->whereIn('verb', ['upload.initiated', 'upload.aborted'])->count())->toBe(2)
        ->and(Outbox::query()->where('event', 'like', 'upload.%')->exists())->toBeFalse();
});

it('records A→B→A→B renames as three distinct occurrences (V06)', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('A');

    foreach (['B', 'A', 'B'] as $name) {
        $drive->nodes()->rename($node, $name);
    }

    $rows = Outbox::query()->where('event', EventType::NodeRenamed->value)->orderBy('id')->get();
    $names = Activity::query()->where('verb', EventType::NodeRenamed->value)->orderBy('id')->get()->pluck('context.to')->all();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('event_id')->unique())->toHaveCount(3)
        ->and($names)->toBe(['B', 'A', 'B']);
});

it('records trash→restore→trash as three lifecycle occurrences (V06)', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('Lifecycle');

    $drive->nodes()->trash($node);
    $drive->trash()->restore($node);
    $drive->nodes()->trash($node);

    $verbs = Activity::query()->whereIn('verb', [EventType::NodeTrashed->value, EventType::NodeRestored->value])->orderBy('id')->toBase()->pluck('verb')->all();
    $sequences = Outbox::query()->whereIn('event', [EventType::NodeTrashed->value, EventType::NodeRestored->value])->orderBy('id')->get()->pluck('payload.sequence')->all();

    expect($verbs)->toBe(['node.trashed', 'node.restored', 'node.trashed'])
        // Each occurrence carries the node's sequence after its own change.
        ->and($sequences)->toBe([$sequences[0], $sequences[0] + 1, $sequences[0] + 2]);
});
