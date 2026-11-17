<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox\Exceptions;

use Override;
use Packman\Attributes\Internal;
use Packman\Errors\Contracts\DescribesError;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use RuntimeException;
use Vaulter\Errors\VaulterErrorCode;

/**
 * A relay worker or reconciler found its lease taken over: the fenced write matched no row, so everything
 * it did under that lease is rolled back or discarded. Internal to the relay; never reaches a host.
 */
#[Internal]
final class LeaseLostException extends RuntimeException implements DescribesError
{
    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::LeaseLost, category: ErrorCategory::Conflict, retryable: true);
    }
}
