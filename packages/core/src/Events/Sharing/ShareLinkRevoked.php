<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Documents\Document;

final readonly class ShareLinkRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Document $document,
        public int|string|null $actorId = null,
    ) {}
}
