<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Errors\VaulterErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class SequenceOverflowException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] The node sequence is exhausted.');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: VaulterErrorCode::NodeSequenceExhausted, category: ErrorCategory::Conflict);
    }

    public function problemCode(): string
    {
        return 'node_sequence_exhausted';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
