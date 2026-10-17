<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Sharing;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A share token that does not open anything. Unknown, revoked, rotated, expired and
 * spent links, a visitor outside the audience's tenant and a node outside the link's
 * scope all answer the same, so a token cannot be probed.
 */
#[Api]
final class ShareNotFoundException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function opaque(): self
    {
        return new self(
            '[Vaulter] Share link not found or no longer available. '
            .'Why: the token is unknown, revoked, rotated, expired or used up, or the requested node is outside the link. '
            .'Fix: ask the owner for a current link. '
            .'Docs: '.self::docs('share-not-found'),
        );
    }

    public function problemCode(): string
    {
        return 'share_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
