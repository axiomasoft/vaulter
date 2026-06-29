<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\ShareLinkRevoked;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentShare;
use Vaulter\Repositories\ShareStoreRepository;

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
