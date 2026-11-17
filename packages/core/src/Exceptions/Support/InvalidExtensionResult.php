<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Support;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A module's contribution to a slot returned something its slot does not allow (a reserved or duplicate
 * fragment key, a fragment of the wrong type, a middleware that skipped or repeated the operation or
 * replaced its result). An integration error, never a silent skip; never retried, never cached.
 */
#[Api]
final class InvalidExtensionResult extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function reservedKey(string $slot, string $key): self
    {
        return new self(sprintf(
            '[Vaulter] %s contributes the reserved fragment key "%s". Why: the snapshot owns that key. Fix: contribute under an own key.',
            $slot,
            $key,
        ));
    }

    public static function duplicateKey(string $slot, string $key): self
    {
        return new self(sprintf(
            '[Vaulter] %s is contributed twice under the fragment key "%s". Why: one fragment per key. Fix: deactivate one of the contributing modules.',
            $slot,
            $key,
        ));
    }

    public static function invalidFragment(string $slot, string $key, string $expected): self
    {
        return new self(sprintf(
            '[Vaulter] %s returned an invalid "%s" fragment. Why: a fragment must be %s keyed by a node id of the page. Fix: return only that.',
            $slot,
            $key,
            $expected,
        ));
    }

    public static function nextCalls(string $middleware, string $operation, int $calls): self
    {
        return new self(sprintf(
            '[Vaulter] The operation middleware %s called next %d time(s) for "%s". Why: a middleware runs the operation exactly once. Fix: call $next once and return its result.',
            $middleware,
            $calls,
            $operation,
        ));
    }

    public static function foreignOperation(string $middleware, string $operation): self
    {
        return new self(sprintf(
            '[Vaulter] The operation middleware %s passed another operation to next for "%s". Why: a middleware cannot change the operation or its context. Fix: pass the operation it was given.',
            $middleware,
            $operation,
        ));
    }

    public static function replacedResult(string $middleware, string $operation): self
    {
        return new self(sprintf(
            '[Vaulter] The operation middleware %s replaced the result of "%s". Why: the result is built by the operation from its locked state. Fix: return exactly what $next returned.',
            $middleware,
            $operation,
        ));
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::ExtensionFailed, category: ErrorCategory::Internal);
    }

    public function problemCode(): string
    {
        return 'extension_failed';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
