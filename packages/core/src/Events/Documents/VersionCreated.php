<?php

declare(strict_types=1);

namespace Vaulter\Events\Documents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Documents\DocumentVersion;

final readonly class VersionCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentVersion $version,
        public int|string|null $actorId = null,
    ) {}
}
