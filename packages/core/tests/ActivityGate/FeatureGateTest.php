<?php

declare(strict_types=1);

use Vaulter\Models\Activity\Activity;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

/**
 * The 'activity' feature gate (§2.9). Booted with the feature OFF
 * (ActivityDisabledTestCase), the RecordsActivity subscriber is never registered,
 * so the Drive events fire unobserved and the feed stays empty.
 */
beforeEach(function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 7]));
});

it('records NOTHING when the activity feature is disabled', function (): void {
    // Provisioning a storage + creating a folder + uploading all fire audited
    // events; with the gate off none of them is recorded.
    $drive = DriveTestHelpers::drive();
    $drive->createFolder('quiet');
    $drive->upload(DriveTestHelpers::streamFor('bytes'), originalName: 'x.txt');

    expect(Activity::query()->count())->toBe(0);
});
