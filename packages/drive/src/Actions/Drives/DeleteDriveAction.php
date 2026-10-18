<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Drives;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Jobs\PurgeDriveJob;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\DriveStoreRepository;
use Vaulter\Events\EventRecorder;

/**
 * active|archived → deleting, then an asynchronous, idempotent purge of the whole
 * drive: nodes, revisions and blob references first, the drive row last.
 * A drive already being deleted is left alone and its purge is queued again, which
 * is how an interrupted purge resumes.
 */
#[Internal]
final readonly class DeleteDriveAction
{
    public function __construct(private VaulterDatabase $database, private DriveStoreRepository $drives, private EventRecorder $recorder) {}

    public function execute(DriveContext $context): Drive
    {
        return $this->database->transaction(function () use ($context): Drive {
            $this->database->lockAggregate('drives', $context->driveId());
            $drive = $this->drives->lock($context->driveId());
            if ($drive->status !== DriveStatus::Deleting) {
                $drive = $this->drives->setStatus($drive, DriveStatus::Deleting);
                // Nothing of the drive is reachable from now on; its row and the drive.deleted event go with the purge.
                $this->recorder->record(EffectivePermissionsChanged::forDrive(drive: $drive, reason: DriveEventType::DriveDeleted->value, actor: $context->actor->ref(), correlationId: $context->correlationId));
            }
            $driveId = $drive->id;
            $this->database->afterCommit(static fn () => PurgeDriveJob::dispatch($driveId));

            return $drive;
        });
    }
}
