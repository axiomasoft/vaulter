<?php

declare(strict_types=1);

namespace DocVault\Events;

use DocVault\Models\DocumentShare;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class ShareLinkCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public DocumentShare $share,
        public int|string|null $actorId = null,
    ) {}
}
