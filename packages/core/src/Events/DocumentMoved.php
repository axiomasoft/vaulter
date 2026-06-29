<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Document;

final readonly class DocumentMoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public int|string|null $folderId = null,
        public int|string|null $actorId = null,
    ) {}
}
