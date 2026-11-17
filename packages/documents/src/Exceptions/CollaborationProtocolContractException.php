<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Documents\Errors\DocumentsErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class CollaborationProtocolContractException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] Collaboration adapter violated its session contract. Fix: correct the adapter.');
    }

    public function problemCode(): string
    {
        return 'collaboration_protocol_contract';
    }

    public function httpStatus(): int
    {
        return 503;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DocumentsErrorCode::CollaborationProtocolContract,
            category: ErrorCategory::Unavailable,
        );
    }
}
