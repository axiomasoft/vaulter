<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Models\DocumentFolder;
use Vaulter\Repositories\FolderStoreRepository;

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
