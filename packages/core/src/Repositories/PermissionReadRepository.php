<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\Models\Document;
use DocVault\Models\DocumentPermission;
use DocVault\Support\Config;
use Illuminate\Database\Eloquent\Builder;

final class PermissionReadRepository
{
    /**
     * @return Builder<DocumentPermission>
     */
    public function forDocument(Document $document): Builder
    {
        $model = Config::permissionModel();

        return $model::query()->where('document_id', $document->getKey());
    }
}
