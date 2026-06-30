<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Documents;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Documents\DocumentFolder;
use Vaulter\Support\Config;
use Vaulter\ValueObjects\Drive\WorkspaceRef;

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
