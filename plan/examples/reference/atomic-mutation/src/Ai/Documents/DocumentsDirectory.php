<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Documents;

use VaulterRef\Ai\Core\Contracts\ResourceDirectory;
use VaulterRef\Database\Pg;
use VaulterRef\Drive\Ability;
use VaulterRef\Drive\AccessGate;
use VaulterRef\Drive\DriveContext;

/** Target: the Documents binding of ResourceDirectory in vaulter-ai (subset: visibility only). */
final readonly class DocumentsDirectory implements ResourceDirectory
{
    public function __construct(private Pg $owner, private AccessGate $access) {}

    public function visible(DriveContext $context, string $resourceId): bool
    {
        return preg_match('/^[0-9a-f-]{36}$/', $resourceId) === 1
            && $this->access->allows($this->owner, $context, $resourceId, Ability::View);
    }
}
