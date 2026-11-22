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

/** The document's type or active journal requires a command for a full replacement. */
#[Api]
final class DocumentCommandRequiredException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct()
    {
        parent::__construct('[Vaulter] The document must be edited through its command writer.');
    }

    public function problemCode(): string
    {
        return 'document_command_required';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /** @return array<string, mixed> */
    public function problemDetails(): array
    {
        return [];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(code: DocumentsErrorCode::DocumentCommandRequired, category: ErrorCategory::Conflict);
    }
}
