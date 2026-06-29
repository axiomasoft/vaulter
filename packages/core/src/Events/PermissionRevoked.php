<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Document;

final readonly class PermissionRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public string $subjectType,
        public int|string $subjectId,
        public int|string|null $actorId = null,
    ) {}
}
