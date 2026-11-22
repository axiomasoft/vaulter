<?php

declare(strict_types=1);

namespace Vaulter\Documents\Errors;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 problem definitions for all `axiomasoft/vaulter-documents` error codes.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class DocumentsProblemDefinitions implements ProblemDefinitionProvider
{
    private const string URN_PREFIX = 'urn:packman:problem:';

    /** @return iterable<ProblemDefinition> */
    #[Override]
    public function definitions(): iterable
    {
        foreach (DocumentsErrorCode::cases() as $code) {
            [$status, $title, $retryable, $category, $detailKeys] = self::metadata($code);

            yield new ProblemDefinition(
                code: $code,
                type: self::URN_PREFIX.$code->value,
                defaultStatus: $status,
                title: $title,
                retryable: $retryable,
                category: $category,
                detailKeys: $detailKeys,
            );
        }
    }

    /** @return array{0: int, 1: string, 2: bool, 3: ErrorCategory, 4: list<string>} */
    private static function metadata(DocumentsErrorCode $code): array
    {
        return match ($code) {
            DocumentsErrorCode::CollaborationProtocolContract => [503, 'Collaboration protocol contract error', false, ErrorCategory::Unavailable, []],
            DocumentsErrorCode::CollaborationUnavailable => [409, 'Collaboration unavailable', false, ErrorCategory::Conflict, ['context']],
            DocumentsErrorCode::CommentNotFound => [404, 'Comment not found', false, ErrorCategory::NotFound, []],
            DocumentsErrorCode::DocumentCommandRequired => [409, 'Document command required', true, ErrorCategory::Conflict, []],
            DocumentsErrorCode::DocumentContentDegraded => [409, 'Document content degraded', false, ErrorCategory::Conflict, ['context']],
            DocumentsErrorCode::DocumentRevisionNotFound => [404, 'Document revision not found', false, ErrorCategory::NotFound, []],
            DocumentsErrorCode::DocumentStateMismatch => [412, 'Document state mismatch', true, ErrorCategory::Validation, ['node_id']],
            DocumentsErrorCode::DocumentStatePending => [409, 'Document state pending drain', true, ErrorCategory::Conflict, ['node_id']],
            DocumentsErrorCode::DocumentStateTooLarge => [413, 'Document state too large', false, ErrorCategory::Validation, ['context']],
            DocumentsErrorCode::DocumentTypeUnavailable => [409, 'Document type unavailable', false, ErrorCategory::Conflict, ['context']],
            DocumentsErrorCode::DuplicateRegistration => [500, 'Duplicate registration', false, ErrorCategory::Internal, []],
            DocumentsErrorCode::InvalidDocumentContent => [422, 'Invalid document content', false, ErrorCategory::Validation, ['reason']],
            DocumentsErrorCode::NotADocument => [409, 'Node is not a document', false, ErrorCategory::Conflict, []],
            DocumentsErrorCode::UnknownDocumentType => [422, 'Unknown document type', false, ErrorCategory::Validation, []],
            DocumentsErrorCode::UnknownExportFormat => [422, 'Unknown export format', false, ErrorCategory::Validation, ['allowed']],
        };
    }
}
