<?php

declare(strict_types=1);

use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Testing\Fixtures\TestUser;

/**
 * The 'activity' feature gate. Booted with the feature OFF
 * (ActivityDisabledTestCase), the EventRecorder skips the activity sink, so the
 * feed stays empty while the Drive operations still succeed.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

it('records NOTHING when the activity feature is disabled', function (): void {
    // Provisioning a storage + creating a folder + uploading all fire audited
    // events; with the gate off none of them is recorded.
    $drive = DriveTestHelpers::drive();
    $drive->folders()->create('quiet');
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('bytes'), 'x.txt'));

    expect(Activity::query()->count())->toBe(0);
});
