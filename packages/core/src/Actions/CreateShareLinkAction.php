<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\CreateShareData;
use Vaulter\Events\ShareLinkCreated;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentShare;
use Vaulter\Repositories\ShareStoreRepository;

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
