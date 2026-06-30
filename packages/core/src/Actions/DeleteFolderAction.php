<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Models\Documents\DocumentFolder;
use Vaulter\Repositories\Documents\FolderStoreRepository;

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
