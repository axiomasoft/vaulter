<?php

declare(strict_types=1);

namespace Vaulter\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Contracts\PermissionResolver;
use Vaulter\Models\Document;

/**
 * Allows every per-document action but denies creating new documents.
 */
final class NoCreateResolver implements PermissionResolver
{
    public function can(Authenticatable $user, string $action, Document $document): bool
    {
        return true;
    }

    public function canCreate(Authenticatable $user, string $workspaceType, int|string $workspaceId): bool
    {
        return false;
    }
}
