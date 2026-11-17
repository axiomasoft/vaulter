<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Nodes;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class UnsupportedNodeKindException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] This node kind does not support this operation.');
    }

    public function problemCode(): string
    {
        return 'unsupported_node_kind';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public static function forKind(NodeKind $kind): self
    {
        return new self;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::UnsupportedNodeKind,
            category: ErrorCategory::Conflict,
        );
    }
}
