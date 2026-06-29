<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Contracts\PermissionResolver;
use Vaulter\Models\Document;

final class AllowResolver implements PermissionResolver
{
    public function can(Authenticatable $user, string $action, Document $document): bool
    {
        return true;
    }

    public function canCreate(Authenticatable $user, string $workspaceType, int|string $workspaceId): bool
    {
        return true;
    }
}
