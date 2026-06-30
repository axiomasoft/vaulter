<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\Sharing\ShareLinkRevoked;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentShare;
use Vaulter\Repositories\Sharing\ShareStoreRepository;

final readonly class RevokeShareLinkAction
{
    public function __construct(
        private ShareStoreRepository $shares,
    ) {}

    public function execute(Document $document, DocumentShare $share, int|string|null $actorId = null): void
    {
        DB::transaction(fn () => $this->shares->delete($share));

        event(new ShareLinkRevoked($document, $actorId));
    }
}
