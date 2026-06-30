<?php

declare(strict_types=1);

namespace Vaulter\Events\Documents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentVersion;

final readonly class VersionRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public DocumentVersion $version,
        public int|string|null $actorId = null,
    ) {}
}
