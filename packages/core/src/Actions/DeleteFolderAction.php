<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Models\DocumentFolder;
use DocVault\Repositories\FolderStoreRepository;
use Illuminate\Support\Facades\DB;

final readonly class DeleteFolderAction
{
    public function __construct(
        private FolderStoreRepository $folders,
    ) {}

    public function execute(DocumentFolder $folder): void
    {
        DB::transaction(fn () => $this->folders->delete($folder));
    }
}
