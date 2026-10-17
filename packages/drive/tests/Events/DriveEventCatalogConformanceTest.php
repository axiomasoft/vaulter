<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Drive\Data\UploadOptions;
use Vaulter\Drive\Data\Uploads\InitiateUploadInput;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Testing\Events\EventConformance;
use Vaulter\Testing\Fixtures\TestUser;

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
        DriveEventType::DriveCreated, DriveEventType::FolderCreated, DriveEventType::FileUploaded, DriveEventType::FileRevisionAdded,
        DriveEventType::FileRevisionDeleted, DriveEventType::NodeRenamed, DriveEventType::NodeMoved, DriveEventType::NodeCopied,
        DriveEventType::NodeUpdated, DriveEventType::NodeTrashed, DriveEventType::NodeRestored, DriveEventType::NodePurged,
        DriveEventType::DriveArchived, DriveEventType::DriveUnarchived,
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

    EventConformance::assertRecorded([DriveEventType::DriveDeleted]);
    EventConformance::assertAuditedOnly([DriveEventType::UploadInitiated, DriveEventType::UploadAborted]);
});
