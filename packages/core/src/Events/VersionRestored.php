<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentVersion;

final readonly class VersionRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public DocumentVersion $version,
        public int|string|null $actorId = null,
    ) {}
}
