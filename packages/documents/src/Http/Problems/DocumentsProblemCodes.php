<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Problems;

use Vaulter\Documents\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Documents\Exceptions\CollaborationSeedConflictException;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\CommentNotFoundException;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentRevisionNotFoundException;
use Vaulter\Documents\Exceptions\DocumentStateTooLargeException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Exceptions\UnknownDocumentTypeException;
use Vaulter\Documents\Exceptions\UnknownExportFormatException;
use Vaulter\Http\Problems\ProblemCodeSource;

/**
 * The problem codes of the documents package: a stable public contract, so a code is added
 * here, in lang/{en,ru}/problems.php and in the reference docs together.
 */
final class DocumentsProblemCodes implements ProblemCodeSource
{
    public function package(): string
    {
        return 'documents';
    }

    public function translationNamespace(): string
    {
        return 'vaulter-documents';
    }

    public function codes(): array
    {
        return [
            'collaboration_epoch_mismatch' => ['status' => 409, 'exception' => CollaborationEpochMismatchException::class],
            'collaboration_seed_conflict' => ['status' => 409, 'exception' => CollaborationSeedConflictException::class],
            'collaboration_unavailable' => ['status' => 409, 'exception' => CollaborationUnavailableException::class],
            'comment_not_found' => ['status' => 404, 'exception' => CommentNotFoundException::class],
            'document_content_degraded' => ['status' => 409, 'exception' => DocumentContentDegradedException::class],
            'document_revision_not_found' => ['status' => 404, 'exception' => DocumentRevisionNotFoundException::class],
            'document_state_mismatch' => ['status' => 412, 'exception' => StaleDocumentStateException::class],
            'document_state_too_large' => ['status' => 413, 'exception' => DocumentStateTooLargeException::class],
            'document_type_unavailable' => ['status' => 409, 'exception' => DocumentTypeUnavailableException::class],
            'invalid_document_content' => ['status' => 422, 'exception' => InvalidDocumentContentException::class],
            'not_a_document' => ['status' => 409, 'exception' => NotADocumentException::class],
            'unknown_document_type' => ['status' => 422, 'exception' => UnknownDocumentTypeException::class],
            'unknown_export_format' => ['status' => 422, 'exception' => UnknownExportFormatException::class],
        ];
    }
}
