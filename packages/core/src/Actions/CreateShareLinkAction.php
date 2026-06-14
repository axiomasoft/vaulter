<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Data\CreateShareData;
use DocVault\Events\ShareLinkCreated;
use DocVault\Models\Document;
use DocVault\Models\DocumentShare;
use DocVault\Repositories\ShareStoreRepository;
use Illuminate\Support\Facades\DB;

final readonly class CreateShareLinkAction
{
    public function __construct(
        private ShareStoreRepository $shares,
    ) {}

    public function execute(Document $document, CreateShareData $data): DocumentShare
    {
        $share = DB::transaction(fn (): DocumentShare => $this->shares->create($document, $data));

        event(new ShareLinkCreated($share, $data->createdBy));

        return $share;
    }
}
