<?php

declare(strict_types=1);

namespace DocVault\Events;

use DocVault\Models\DocumentVersion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class VersionCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentVersion $version,
        public int|string|null $actorId = null,
    ) {}
}
