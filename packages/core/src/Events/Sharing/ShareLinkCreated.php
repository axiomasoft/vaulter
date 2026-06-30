<?php

declare(strict_types=1);

namespace Vaulter\Events\Sharing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Documents\DocumentShare;

final readonly class ShareLinkCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentShare $share,
        public int|string|null $actorId = null,
    ) {}
}
