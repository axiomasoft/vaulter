<?php

declare(strict_types=1);

namespace DocVault\Tests\Fixtures;

use DocVault\Contracts\PermissionResolver;
use DocVault\Models\Document;
use Illuminate\Contracts\Auth\Authenticatable;

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
