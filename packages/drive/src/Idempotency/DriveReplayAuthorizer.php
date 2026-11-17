<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use Packman\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;

/**
 * The current authorization of a replayed drive lifecycle write (drive.drives.archive|unarchive|delete): Manage on
 * the drive's root now, or the system actor ({@see OperationGuard::authorizeLifecycle()}) — the same gate as the write. A drive that is being deleted is absent
 * before the operation runs at all (DriveHandle::model()), so a retry of a delete that committed answers
 * drive_not_found like any request to that drive.
 */
#[Internal]
final readonly class DriveReplayAuthorizer implements ReplayAuthorizer
{
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard) {}

    public function scope(mixed $result): ResourceRef
    {
        return new ResourceRef('drive', (string) $this->context->driveId());
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        $root = $this->locator->find($this->context, $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
        $this->guard->authorizeLifecycle($this->context, $root);
    }
}
