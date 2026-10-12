<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Drives;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Drives\DriveUnarchived;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Events\EventRecorder;
use Vaulter\Exceptions\Drive\InvalidDriveStateException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\DriveStoreRepository;

/** archived → active. */
final readonly class UnarchiveDriveAction
{
    public function __construct(private VaulterDatabase $database, private DriveStoreRepository $drives, private EventRecorder $recorder) {}

    public function execute(OperationContext $context): Drive
    {
        return $this->database->transaction(function () use ($context): Drive {
            $this->database->lockTree($context->driveId());
            $drive = $this->drives->lock($context->driveId());
            if ($drive->status !== DriveStatus::Archived) {
                throw InvalidDriveStateException::for($drive->id, 'unarchive', $drive->status);
            }
            $drive = $this->drives->setStatus($drive, DriveStatus::Active);
            $this->recorder->record(new DriveUnarchived(drive: $drive, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $drive;
        });
    }
}
