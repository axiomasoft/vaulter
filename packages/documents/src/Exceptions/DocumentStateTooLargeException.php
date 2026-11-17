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

/**
 * A document state exceeded the caller-supplied document state limit.
 * Nothing is written.
 */
#[Api]
final class DocumentStateTooLargeException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(
        public readonly int $sizeBytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct(sprintf(
            '[Vaulter] The collaboration state is %d bytes, above the %d-byte limit. Fix: review the caller-supplied document state limit or split the document. Docs: %s',
            $sizeBytes,
            $maxBytes,
            self::docs('document-state-too-large'),
        ));
    }

    public function problemCode(): string
    {
        return 'document_state_too_large';
    }

    public function httpStatus(): int
    {
        return 413;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['context' => ['size_bytes' => $this->sizeBytes, 'max_bytes' => $this->maxBytes]];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DocumentsErrorCode::DocumentStateTooLarge,
            category: ErrorCategory::Validation,
            details: ['size_bytes' => $this->sizeBytes, 'max_bytes' => $this->maxBytes],
        );
    }
}
