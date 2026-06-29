<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\DocumentVersion;

final readonly class VersionCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentVersion $version,
        public int|string|null $actorId = null,
    ) {}
}
