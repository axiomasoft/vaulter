<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use RuntimeException;
use Throwable;

/**
 * A failure raised by an after-commit callback once the attempt had committed: carried out of the
 * retry loop as it is (never as a previous exception, which the loop would read as a concurrency
 * failure) so a committed attempt is not repeated.
 *
 * @internal
 */
final class AfterCommitFailure extends RuntimeException
{
    public function __construct(public readonly Throwable $original)
    {
        parent::__construct('An after-commit callback failed after the commit.');
    }
}
