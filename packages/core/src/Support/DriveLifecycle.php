<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Access\OperationGuard;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Exceptions\Drive\DriveArchivedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\OperationContext;

/**
 * The drive status as a writer sees it once it holds the tree lock (PLAN2.D37, F05/F06).
 * OperationGuard answers before the lock; archive and delete flip the status under the
 * exclusive tree lock, so a read taken after the writer's own T lock cannot miss a
 * flip that committed in between. An archived drive refuses every non-system writer; a
 * drive being deleted exists for the system 'drive-purge' only.
 *
 * @internal shared by the Drive and Documents Actions; not a public extension point
 */
final readonly class DriveLifecycle
{
    public function __construct(private VaulterConfig $config) {}

    /** Call right after lockTree()/lockTreeShared(), inside the Action's transaction. */
    public function assertWritable(OperationContext $context): void
    {
        $class = $this->config->model('drive');
        $drive = $class::query()->whereKey($context->driveId())->first(['id', 'status']);
        if (!$drive instanceof Drive) {
            throw NodeNotFoundException::forId('drive');
        }
        $system = $context->actor->isSystem();
        if ($drive->status === DriveStatus::Deleting && !($system && $context->actor->ref()->reason === OperationGuard::PURGE_REASON)) {
            throw NodeNotFoundException::forId('drive');
        }
        if ($drive->status === DriveStatus::Archived && !$system) {
            throw DriveArchivedException::forDrive($context->driveId());
        }
    }
}
