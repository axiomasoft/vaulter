<?php

declare(strict_types=1);

namespace DocVault\Tests\Fixtures;

use DocVault\Contracts\PermissionResolver;
use DocVault\Models\Document;
use Illuminate\Contracts\Auth\Authenticatable;

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
