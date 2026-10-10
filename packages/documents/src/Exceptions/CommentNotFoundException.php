<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/**
 * A comment id did not resolve to a comment of the addressed node. A comment of
 * another node answers the same way, so a caller cannot probe foreign threads.
 */
final class CommentNotFoundException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'comment_not_found';

    private const int HTTP_STATUS = 404;

    public static function forNode(mixed $nodeId, mixed $commentId): self
    {
        return new self(
            sprintf(
                '[Vaulter] Comment [%s] was not found on node [%s]. ',
                Identity::stringify($commentId),
                Identity::stringify($nodeId),
            )
            .'Why: the comment does not exist or belongs to a different node. '
            .'Fix: list the node comments and use an id from that list. '
            .'Docs: '.self::docs('comment-not-found'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }
}
