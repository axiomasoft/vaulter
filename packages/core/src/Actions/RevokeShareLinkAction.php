<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Events\ShareLinkRevoked;
use DocVault\Models\Document;
use DocVault\Models\DocumentShare;
use DocVault\Repositories\ShareStoreRepository;
use Illuminate\Support\Facades\DB;

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
