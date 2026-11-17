<?php

declare(strict_types=1);

namespace Vaulter\Drive\Support;

use Packman\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\DriveContext;

/**
 * The drive status as a writer sees it once it holds the tree lock.
 * OperationGuard answers before the lock; archive and delete flip the status under the
 * exclusive tree lock, so a read taken after the writer's own T lock cannot miss a
 * flip that committed in between. An archived drive refuses every non-system writer; a
 * drive being deleted exists for the system 'drive-purge' only.
 *
 * shared by the Drive and Documents Actions; not a public extension point
 */
#[Internal]
final readonly class DriveLifecycle
{
    public function __construct(private DriveOptions $config) {}

    /** Call right after lockAggregate()/lockAggregateShared(), inside the Action's transaction. */
    public function assertWritable(DriveContext $context): void
    {
        $system = $context->actor->isSystem();
        $this->assertState(
            driveId: $context->driveId(),
            system: $system,
            purge: $system && $context->actor->ref()->reason === OperationGuard::PURGE_REASON,
        );
    }

    /** A hub writes for a user; its service transport grants no maintenance exemption. */
    public function assertUserWritable(int|string $driveId): void
    {
        $this->assertState(driveId: $driveId, system: false, purge: false);
    }

    private function assertState(int|string $driveId, bool $system, bool $purge): void
    {
        $class = $this->config->model('drive');
        $drive = $class::query()->whereKey($driveId)->first(['id', 'status']);
        if (!$drive instanceof Drive) {
            throw NodeNotFoundException::forId('drive');
        }

        if ($drive->status === DriveStatus::Deleting && !$purge) {
            throw NodeNotFoundException::forId('drive');
        }

        if ($drive->status === DriveStatus::Archived && !$system) {
            throw DriveArchivedException::forDrive($driveId);
        }
    }
}
