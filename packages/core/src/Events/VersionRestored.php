<?php

declare(strict_types=1);

namespace DocVault\Events;

use DocVault\Models\Document;
use DocVault\Models\DocumentVersion;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class VersionRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public DocumentVersion $version,
        public int|string|null $actorId = null,
    ) {}
}
