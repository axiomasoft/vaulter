<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Models\DocumentFolder;
use DocVault\Repositories\FolderStoreRepository;
use Illuminate\Support\Facades\DB;

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
