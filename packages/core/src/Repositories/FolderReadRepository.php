<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\Models\DocumentFolder;
use DocVault\Support\Config;
use DocVault\ValueObjects\WorkspaceRef;
use Illuminate\Database\Eloquent\Builder;

final class FolderReadRepository
{
    /**
     * @return Builder<DocumentFolder>
     */
    public function forWorkspace(WorkspaceRef $workspace): Builder
    {
        $model = Config::folderModel();

        return $model::query()
            ->forWorkspace($workspace)
            ->orderBy('order');
    }

    public function find(int|string $id): ?DocumentFolder
    {
        $model = Config::folderModel();

        return $model::query()->find($id);
    }
}
