<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\Models\Document;
use DocVault\Models\DocumentShare;
use DocVault\Support\Config;
use Illuminate\Database\Eloquent\Builder;

final class ShareReadRepository
{
    /**
     * @return Builder<DocumentShare>
     */
    public function forDocument(Document $document): Builder
    {
        $model = Config::shareModel();

        return $model::query()
            ->where('document_id', $document->getKey())
            ->latest('created_at');
    }

    /**
     * Resolve a share by token, only if it has not expired.
     */
    public function findActiveByToken(string $token): ?DocumentShare
    {
        $model = Config::shareModel();
        $share = $model::query()->where('token', $token)->first();

        if ($share === null || $share->isExpired()) {
            return null;
        }

        return $share;
    }
}
