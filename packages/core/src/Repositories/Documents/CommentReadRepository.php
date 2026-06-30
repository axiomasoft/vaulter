<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Documents;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentComment;
use Vaulter\Support\Config;

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
