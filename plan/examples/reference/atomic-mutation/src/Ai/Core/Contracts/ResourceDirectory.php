<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Contracts;

use VaulterRef\Drive\DriveContext;

/**
 * Target: packages/core/src/Contracts/ResourceDirectory.php in vaulter-ai (subset). Used only to choose between
 * resource_unavailable and a disclosing answer (operation_unknown, idempotency_conflict) for a key the owner
 * cannot answer itself.
 */
interface ResourceDirectory
{
    public function visible(DriveContext $context, string $resourceId): bool;
}
