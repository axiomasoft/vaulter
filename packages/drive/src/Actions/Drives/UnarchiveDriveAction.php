<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Drives;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Data\Snapshots\Mapping\DriveSnapshotMapper;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Events\Drives\DriveUnarchived;
use Vaulter\Drive\Exceptions\InvalidDriveStateException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\AggregateVersion;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\DriveStoreRepository;
use Vaulter\Events\EventRecorder;

/** archived → active. */
#[Internal]
final readonly class UnarchiveDriveAction
{
    public function __construct(private VaulterDatabase $database, private DriveStoreRepository $drives, private EventRecorder $recorder, private AggregateVersion $versions, private DriveSnapshotMapper $driveSnapshots) {}

    /** @param int|null $expectedVersion the drive's version the caller read (If-Match); compared under the row lock, null skips it */
    public function execute(DriveContext $context, ?int $expectedVersion = null): Drive
    {
        return $this->database->transaction(function () use ($context, $expectedVersion): Drive {
            $this->database->lockAggregate('drives', $context->driveId());
            $drive = $this->drives->lock($context->driveId());
            $this->versions->assertExpected($drive, 'version', $expectedVersion, AggregateVersion::DRIVE);
            if ($drive->status !== DriveStatus::Archived) {
                throw InvalidDriveStateException::for($drive->id, 'unarchive', $drive->status);
            }
            $drive = $this->drives->setStatus($drive, DriveStatus::Active);
            $this->versions->bump($drive, 'version', AggregateVersion::DRIVE);
            $this->recorder->record(new DriveUnarchived(driveSnapshots: $this->driveSnapshots, drive: $drive, actor: $context->actor->ref(), correlationId: $context->correlationId));
            $this->recorder->record(EffectivePermissionsChanged::forDrive(drive: $drive, reason: DriveEventType::DriveUnarchived->value, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $drive;
        });
    }
}
