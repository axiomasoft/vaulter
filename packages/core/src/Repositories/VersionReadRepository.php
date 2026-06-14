<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\Models\Document;
use DocVault\Models\DocumentVersion;
use DocVault\Support\Config;
use Illuminate\Database\Eloquent\Builder;

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
