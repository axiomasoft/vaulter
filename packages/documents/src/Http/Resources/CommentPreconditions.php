<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Support\Preconditions;

/**
 * What a conditional write of a comment (resolve, delete) expects: the version in the comment's tag.
 *
 * As for the other versioned aggregates, the If-Match header is compared only after the comment was found as this
 * caller (404) and the caller was seen to hold the ability the write needs (403); then it is required (428 without it
 * or with `*`) and must equal the current tag (412 stale_write). The version it names is what the operation compares
 * again under the comment's row lock, so this check only answers earlier.
 *
 * On a route of a receipt operation a retry under its Idempotency-Key repeats the If-Match of the first request,
 * stale once that one committed (a deleted comment is gone altogether): with a key the write gets a version no row
 * holds, and the operation answers from the receipt, or refuses — and stores — the stale write. Without a key the
 * refusal stands.
 */
#[Internal]
final readonly class CommentPreconditions
{
    public function __construct(private DocumentsManager $documents) {}

    /** The version a resolution (`$delete` false) or a deletion of the comment expects. */
    public function expectedVersion(Request $request, DriveHandle $drive, string $nodeId, string $commentId, bool $delete): int
    {
        $held = null;
        try {
            $comment = $this->documents->comments($drive)->forWrite($nodeId, $commentId, $delete);
            $held = $comment->version;
            if (!Preconditions::matches($request, CommentResource::tag($comment))) {
                throw StaleWriteException::of(NodeComment::AGGREGATE, $comment->id);
            }

            return $held;
        } catch (StaleWriteException|NodeNotFoundException|CommentNotFoundException $refusal) {
            if (ReadIdempotencyKey::key($request) === null) {
                throw $refusal;
            }

            return $held === PHP_INT_MAX ? PHP_INT_MAX - 1 : PHP_INT_MAX;
        }
    }
}
