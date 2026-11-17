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
final class UnknownDocumentTypeException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'unknown_document_type';

    private const int HTTP_STATUS = 422;

    /**
     * @param list<string> $allowed
     */
    public static function forType(string $type, array $allowed): self
    {
        $list = implode(', ', $allowed);

        return new self(
            sprintf('[Vaulter] Unknown document type [%s]. ', $type)
            .sprintf('Why: it is not a registered document type [%s]. ', $list)
            .'Fix: add its class to config(vaulter-documents.types), tag it vaulter.document_types, '
            .'or call Documents::types()->register() from a service provider. '
            .'Docs: '.self::docs('unknown-document-type'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DocumentsErrorCode::UnknownDocumentType,
            category: ErrorCategory::Validation,
        );
    }
}
