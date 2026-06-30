<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Models\Documents\Document;

/**
 * Implemented by the host application so Vaulter can delegate authorization
 * to its existing role system.
 */
interface PermissionResolver
{
    /**
     * May the user perform the given action on the document?
     *
     * @param string $action view|edit|comment|manage|delete
     */
    public function can(Authenticatable $user, string $action, Document $document): bool;

    /**
     * May the user create documents inside the given workspace?
     */
    public function canCreate(Authenticatable $user, string $workspaceType, int|string $workspaceId): bool;
}
