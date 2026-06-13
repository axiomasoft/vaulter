<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\Models\DocumentFolder;
use DocVault\Support\Config;

final class FolderStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): DocumentFolder
    {
        $model = Config::folderModel();
        $folder = new $model;
        $folder->fill($attributes);
        $folder->save();

        return $folder;
    }

    public function rename(DocumentFolder $folder, string $name): DocumentFolder
    {
        $folder->forceFill(['name' => $name])->save();

        return $folder;
    }

    public function move(DocumentFolder $folder, int|string|null $parentId): DocumentFolder
    {
        $folder->forceFill(['parent_id' => $parentId])->save();

        return $folder;
    }

    public function delete(DocumentFolder $folder): void
    {
        $folder->delete();
    }
}
