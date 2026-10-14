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
use Vaulter\Operations\DriveContext;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Events\EventConformance;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The drive events, produced by the real Actions, carry exactly the data their
 * EventSpec declares and nothing an envelope must not hold.
 */
uses(TestCase::class, RefreshDatabase::class, EnablesWebhooksModule::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

it('records every drive event with the data its spec declares', function (): void {
    $drive = DriveTestHelpers::drive();
    $actor = $drive->actingAs(DriveTestHelpers::actor($drive, 7));
    $a = $actor->folders()->create('Secret A');
    $b = $actor->folders()->create('Secret B');
    $file = $actor->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('v1'), 'private.txt'), new UploadOptions(parent: $a));
    $actor->files()->addRevision($file, UploadSource::fromStream(DriveTestHelpers::streamFor('v2-bytes'), 'private.txt'));
    $first = DriveTestHelpers::model($file)->file()->firstOrFail()->versions()->where('number', 1)->value('id');
    $actor->files()->deleteRevision($file, $first);
    $actor->nodes()->rename($b, 'Secret C');
    $actor->nodes()->move($b, $a);
    $actor->nodes()->copy($b, $drive->root());
    $actor->nodes()->setProperties($b, 'acme', ['label' => 'confidential']);
    $actor->nodes()->trash($b);
    $actor->trash()->restore($b);
    $actor->nodes()->trash($b);
    $actor->trash()->purge($b);
    $drive->archive();
    $drive->unarchive();

    EventConformance::assertRecorded([
        EventType::DriveCreated, EventType::FolderCreated, EventType::FileUploaded, EventType::FileRevisionAdded,
        EventType::FileRevisionDeleted, EventType::NodeRenamed, EventType::NodeMoved, EventType::NodeCopied,
        EventType::NodeUpdated, EventType::NodeTrashed, EventType::NodeRestored, EventType::NodePurged,
        EventType::DriveArchived, EventType::DriveUnarchived,
    ]);
});

it('records drive.deleted and the upload sessions with their specs', function (): void {
    $drive = DriveTestHelpers::drive();
    $context = DriveContext::system(drive: $drive->model(), reason: 'conformance');
    $session = app(UploadSessionManager::class)->initiate($context, new InitiateUploadInput(
        protocol: UploadProtocol::Tus,
        targetName: 'big.bin',
        totalSizeBytes: 4,
    ));
    app(UploadSessionManager::class)->abort($session);
    $id = $drive->model()->getKey();
    $drive->delete();
    while (!app(PurgeDriveStepAction::class)->execute($id)) {
        // One batch per step; a fresh drive finishes in the first.
    }

    EventConformance::assertRecorded([EventType::DriveDeleted]);
    EventConformance::assertAuditedOnly([EventType::UploadInitiated, EventType::UploadAborted]);
});
