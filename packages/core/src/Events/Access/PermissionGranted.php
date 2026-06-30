<?php

declare(strict_types=1);

namespace Vaulter\Events\Access;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Documents\DocumentPermission;

final readonly class PermissionGranted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentPermission $permission,
        public int|string|null $actorId = null,
    ) {}
}
