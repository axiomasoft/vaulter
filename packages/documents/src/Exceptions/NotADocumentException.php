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
use Vaulter\Support\Identity;

/**
 * A document content/version operation targeted a node that is not kind=document
 * . A folder/file/shortcut has no document aggregate, so the operation
 * is rejected rather than silently writing an orphan revision.
 */
#[Api]
final class NotADocumentException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'not_a_document';

    private const int HTTP_STATUS = 409;

    public static function forNode(mixed $nodeId): self
    {
        return new self(
            sprintf('[Vaulter] Node [%s] is not a document node. ', Identity::stringify($nodeId))
            .'Why: document content/version operations require a kind=document node '
            .'(its payload lives in its v_documents aggregate). '
            .'Fix: create the document via CreateDocumentAction first, or target a document node. '
            .'Docs: '.self::docs('not-a-document-node'),
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
            code: DocumentsErrorCode::NotADocument,
            category: ErrorCategory::Conflict,
        );
    }
}
