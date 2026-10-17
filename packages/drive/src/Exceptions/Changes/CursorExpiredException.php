<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Changes;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The entries after a change cursor are no longer all there: the retention prune
 * deleted some of them, or the journal was reset. The client resynchronizes.
 */
#[Api]
final class CursorExpiredException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function pruned(): self
    {
        return new self(
            '[Vaulter] The change cursor has expired. '
            .'Why: entries after it were removed by the retention prune (vaulter.changes.retention_days). '
            .'Fix: take a new startCursor(), list the drive again and replace the local copy. '
            .'Docs: '.self::docs('cursor-expired'),
        );
    }

    public static function reset(): self
    {
        return new self(
            '[Vaulter] The change cursor has expired. '
            .'Why: the drive\'s change journal was reset (vaulter:changes:reset). '
            .'Fix: take a new startCursor(), list the drive again and replace the local copy. '
            .'Docs: '.self::docs('cursor-expired'),
        );
    }

    public function problemCode(): string
    {
        return 'cursor_expired';
    }

    public function httpStatus(): int
    {
        return 410;
    }
}
