<?php

declare(strict_types=1);

namespace DocVault\Events;

use DocVault\Models\DocumentPermission;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PermissionGranted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentPermission $permission,
        public int|string|null $actorId = null,
    ) {}
}
