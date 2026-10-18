<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Operations;

use LogicException;
use Vaulter\Attributes\Api;

/**
 * A mutating operation ran that no active module declares a policy for (D47, I12), or one declared by another
 * module than the one the spec names. A developer error, never a client one: it carries no problem code (the
 * HTTP surface answers 500 `internal_error`) and is thrown before any claim or transaction.
 */
#[Api]
final class UnknownOperationException extends LogicException
{
    public static function undeclared(string $operation, string $module): self
    {
        return new self(sprintf('[Vaulter] The mutating operation "%s" of the module "%s" has no policy in the operation catalogue. Fix: declare it in an OperationPolicySource of the module with ModuleBuilder::operations().', $operation, $module));
    }

    public static function foreign(string $operation, string $module, string $owner): self
    {
        return new self(sprintf('[Vaulter] The operation "%s" runs as the module "%s", but the module "%s" declares its policy.', $operation, $module, $owner));
    }
}
