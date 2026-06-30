<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Documents;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentVersion;
use Vaulter\Support\Config;

final class VersionReadRepository
{
    /**
     * @return Builder<DocumentVersion>
     */
    public function forDocument(Document $document): Builder
    {
        $model = Config::versionModel();

        return $model::query()
            ->where('document_id', $document->getKey())
            ->latest('created_at');
    }

    public function find(int|string $id): ?DocumentVersion
    {
        $model = Config::versionModel();

        return $model::query()->find($id);
    }
}
