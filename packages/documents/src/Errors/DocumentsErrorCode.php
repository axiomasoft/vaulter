<?php

declare(strict_types=1);

namespace Vaulter\Documents\Errors;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Canonical Packman error codes owned by `axiomasoft/vaulter-documents`.
 */
#[Api(since: '1.0')]
enum DocumentsErrorCode: string implements ErrorCode
{
    case CollaborationProtocolContract = 'documents.collaboration_protocol_contract';
    case CollaborationUnavailable = 'documents.collaboration_unavailable';
    case CommentNotFound = 'documents.comment_not_found';
    case DocumentContentDegraded = 'documents.document_content_degraded';
    case DocumentRevisionNotFound = 'documents.document_revision_not_found';
    case DocumentStateMismatch = 'documents.document_state_mismatch';
    case DocumentStatePending = 'documents.document_state_pending';
    case DocumentStateTooLarge = 'documents.document_state_too_large';
    case DocumentTypeUnavailable = 'documents.document_type_unavailable';
    case DuplicateRegistration = 'documents.duplicate_registration';
    case InvalidDocumentContent = 'documents.invalid_document_content';
    case NotADocument = 'documents.not_a_document';
    case UnknownDocumentType = 'documents.unknown_document_type';
    case UnknownExportFormat = 'documents.unknown_export_format';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}
