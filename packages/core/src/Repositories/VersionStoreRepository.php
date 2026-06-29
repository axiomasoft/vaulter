<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Vaulter\Models\Document;
use Vaulter\Models\DocumentVersion;
use Vaulter\Support\Config;

final class VersionStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): DocumentVersion
    {
        $model = Config::versionModel();
        $version = new $model;
        $version->fill($attributes);
        $version->save();

        return $version;
    }

    /**
     * Keep the $keep most recent versions of a document, delete the rest.
     * Returns the number of pruned rows (0 when $keep <= 0 = unlimited).
     */
    public function pruneOldest(Document $document, int $keep): int
    {
        if ($keep <= 0) {
            return 0;
        }

        $model = Config::versionModel();

        $keepIds = $model::query()
            ->where('document_id', $document->getKey())
            ->latest('created_at')
            ->limit($keep)
            ->pluck('id');

        $deleted = $model::query()
            ->where('document_id', $document->getKey())
            ->whereNotIn('id', $keepIds)
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }
}
