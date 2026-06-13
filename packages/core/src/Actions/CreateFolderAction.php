<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Data\CreateFolderData;
use DocVault\Models\DocumentFolder;
use DocVault\Repositories\FolderStoreRepository;
use Illuminate\Support\Facades\DB;

final readonly class CreateFolderAction
{
    public function __construct(
        private FolderStoreRepository $folders,
    ) {}

    public function execute(CreateFolderData $data): DocumentFolder
    {
        return DB::transaction(fn (): DocumentFolder => $this->folders->create([
            'workspace_type' => $data->workspaceType,
            'workspace_id' => $data->workspaceId,
            'parent_id' => $data->parentId,
            'name' => $data->name,
            'order' => $data->order,
        ]));
    }
}
