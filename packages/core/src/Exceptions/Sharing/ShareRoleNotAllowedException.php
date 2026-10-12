<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Sharing;

use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** A share link cannot carry this role for this audience. */
final class ShareRoleNotAllowedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function for(string $role, ShareAudience $audience): self
    {
        return new self(sprintf('[Vaulter] A share link cannot carry the role [%s] for the audience [%s]. ', $role, $audience->value)
            .'Why: links grant previewer, reader or commenter to any audience, writer only to authenticated or tenant visitors, and never organizer or owner. '
            .'Fix: pick an allowed role, or restrict the audience. '
            .'Docs: '.self::docs('share-role-not-allowed'));
    }

    public function problemCode(): string
    {
        return 'share_role_not_allowed';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
