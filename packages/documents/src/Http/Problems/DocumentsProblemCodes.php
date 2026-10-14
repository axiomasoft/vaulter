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
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;
use Vaulter\Http\Problems\Retryable;

/**
 * The problem codes of the documents package: a stable public contract, so a code is added
 * here, in lang/{en,ru}/problems.php and in the reference docs together. The `collaboration_*`
 * codes belong to the collaboration concept and move with it in P5.
 */
final class DocumentsProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter-documents';
    }

    public function codes(): array
    {
        return [
            'collaboration_epoch_mismatch' => new ProblemSpec(409, CollaborationEpochMismatchException::class, Retryable::AfterRefetch, ReceiptPolicy::Cacheable),
            'collaboration_seed_conflict' => new ProblemSpec(409, CollaborationSeedConflictException::class, Retryable::AfterRefetch, ReceiptPolicy::Cacheable),
            // The host's configuration decides, not the request: waiting does not help and a receipt would outlive a config change.
            'collaboration_unavailable' => new ProblemSpec(409, CollaborationUnavailableException::class),
            'comment_not_found' => new ProblemSpec(404, CommentNotFoundException::class),
            'document_content_degraded' => new ProblemSpec(409, DocumentContentDegradedException::class, receipt: ReceiptPolicy::Cacheable),
            'document_revision_not_found' => new ProblemSpec(404, DocumentRevisionNotFoundException::class),
            'document_state_mismatch' => new ProblemSpec(412, StaleDocumentStateException::class, Retryable::AfterRefetch, ReceiptPolicy::Cacheable),
            'document_state_too_large' => new ProblemSpec(413, DocumentStateTooLargeException::class, receipt: ReceiptPolicy::Cacheable),
            'document_type_unavailable' => new ProblemSpec(409, DocumentTypeUnavailableException::class, receipt: ReceiptPolicy::Cacheable),
            'invalid_document_content' => new ProblemSpec(422, InvalidDocumentContentException::class, receipt: ReceiptPolicy::Cacheable),
            'not_a_document' => new ProblemSpec(409, NotADocumentException::class, receipt: ReceiptPolicy::Cacheable),
            'unknown_document_type' => new ProblemSpec(422, UnknownDocumentTypeException::class, receipt: ReceiptPolicy::Cacheable),
            'unknown_export_format' => new ProblemSpec(422, UnknownExportFormatException::class, receipt: ReceiptPolicy::Cacheable),
        ];
    }
}
