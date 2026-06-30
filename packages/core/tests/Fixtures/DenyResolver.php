<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Contracts\Access\PermissionResolver;
use Vaulter\Models\Documents\Document;

final class DenyResolver implements PermissionResolver
{
    public function can(Authenticatable $user, string $action, Document $document): bool
    {
        return false;
    }

    public function canCreate(Authenticatable $user, string $workspaceType, int|string $workspaceId): bool
    {
        return false;
    }
}
