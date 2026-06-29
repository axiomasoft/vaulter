<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Models\DocumentFolder;
use Vaulter\Repositories\FolderStoreRepository;

final readonly class RenameFolderAction
{
    public function __construct(
        private FolderStoreRepository $folders,
    ) {}

    public function execute(DocumentFolder $folder, string $name): DocumentFolder
    {
        return DB::transaction(fn (): DocumentFolder => $this->folders->rename($folder, $name));
    }
}
