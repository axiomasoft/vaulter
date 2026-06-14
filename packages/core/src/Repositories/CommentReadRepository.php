<?php

declare(strict_types=1);

namespace DocVault\Repositories;

use DocVault\Models\Document;
use DocVault\Models\DocumentComment;
use DocVault\Support\Config;
use Illuminate\Database\Eloquent\Builder;

final class CommentReadRepository
{
    /**
     * @return Builder<DocumentComment>
     */
    public function forDocument(Document $document): Builder
    {
        $model = Config::commentModel();

        return $model::query()
            ->where('document_id', $document->getKey())
            ->oldest('created_at');
    }

    public function find(int|string $id): ?DocumentComment
    {
        $model = Config::commentModel();

        return $model::query()->find($id);
    }
}
