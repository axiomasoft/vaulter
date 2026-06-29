<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentPermission;
use Vaulter\Support\Config;

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
