<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\CreateCommentData;
use Vaulter\Events\Documents\CommentAdded;
use Vaulter\Models\Documents\Document;
use Vaulter\Models\Documents\DocumentComment;
use Vaulter\Repositories\Documents\CommentStoreRepository;

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
