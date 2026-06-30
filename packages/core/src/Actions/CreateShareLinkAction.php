<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\CreateShareData;
use Vaulter\Events\Sharing\ShareLinkCreated;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentShare;
use Vaulter\Repositories\Sharing\ShareStoreRepository;

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
