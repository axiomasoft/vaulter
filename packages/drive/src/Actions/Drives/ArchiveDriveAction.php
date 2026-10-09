<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Drives;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Drives\DriveArchived;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Drive\InvalidDriveStateException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\DriveStoreRepository;

/** active → archived: the drive becomes read-only (PLAN2.D37). */
final readonly class ArchiveDriveAction
{
    public function __construct(private VaulterDatabase $database, private DriveStoreRepository $drives) {}

    public function execute(OperationContext $context): Drive
    {
        return $this->database->transaction(function () use ($context): Drive {
            // T exclusive: no tree write of this drive is in flight when the status flips.
            $this->database->lockTree($context->driveId());
            $drive = $this->drives->lock($context->driveId());
            if ($drive->status !== DriveStatus::Active) {
                throw InvalidDriveStateException::for($drive->id, 'archive', $drive->status);
            }
            $drive = $this->drives->setStatus($drive, DriveStatus::Archived);
            event(new DriveArchived(drive: $drive, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return $drive;
        });
    }
}
