<?php

declare(strict_types=1);

namespace DocVault\Events;

use DocVault\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class DocumentRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public int|string|null $actorId = null,
    ) {}
}
