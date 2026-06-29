<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\DocumentPermission;

final readonly class PermissionGranted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentPermission $permission,
        public int|string|null $actorId = null,
    ) {}
}
