<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Packman\Attributes\Internal;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;

#[Spi(SpiKind::Call)]
final readonly class NodeEditAccess
{
    #[Internal]
    public function __construct(private NodeLocator $nodes, private OperationGuard $access) {}

    public function canEdit(DriveContext $context, int|string $node): bool
    {
        try {
            $this->access->authorize($context, Ability::Edit, $this->nodes->find($context, $node), OperationKind::Mutation);

            return true;
        } catch (NodeNotFoundException|PermissionDeniedException|ProfileRestrictionException|DriveArchivedException) {
            return false;
        }
    }
}
