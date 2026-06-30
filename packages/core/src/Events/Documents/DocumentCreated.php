<?php

declare(strict_types=1);

namespace Vaulter\Events\Documents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Documents\Document;

final readonly class DocumentCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public int|string|null $actorId = null,
    ) {}
}
