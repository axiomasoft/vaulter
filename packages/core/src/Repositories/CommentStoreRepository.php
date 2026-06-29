<?php

declare(strict_types=1);

namespace Vaulter\Repositories;

use Illuminate\Support\Carbon;
use Vaulter\Models\DocumentComment;
use Vaulter\Support\Config;

final class CommentStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): DocumentComment
    {
        $model = Config::commentModel();
        $comment = new $model;
        $comment->fill($attributes);
        $comment->save();

        return $comment;
    }

    public function resolve(DocumentComment $comment): DocumentComment
    {
        $comment->forceFill(['resolved_at' => Carbon::now()])->save();

        return $comment;
    }

    public function delete(DocumentComment $comment): void
    {
        $comment->delete();
    }
}
