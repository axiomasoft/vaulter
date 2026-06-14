<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Data\CreateCommentData;
use DocVault\Events\CommentAdded;
use DocVault\Models\Document;
use DocVault\Models\DocumentComment;
use DocVault\Repositories\CommentStoreRepository;
use Illuminate\Support\Facades\DB;

final readonly class AddCommentAction
{
    public function __construct(
        private CommentStoreRepository $comments,
    ) {}

    public function execute(Document $document, CreateCommentData $data): DocumentComment
    {
        $comment = DB::transaction(fn (): DocumentComment => $this->comments->create([
            'document_id' => $document->getKey(),
            'parent_id' => $data->parentId,
            'author_type' => $data->authorType,
            'author_id' => $data->authorId,
            'body' => $data->body,
            'anchor' => $data->anchor,
        ]));

        event(new CommentAdded($comment));

        return $comment;
    }
}
