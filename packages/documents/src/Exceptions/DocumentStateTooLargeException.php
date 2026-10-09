<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A collaboration store carried a binary Yjs state above
 * vaulter-documents.collaboration.max_state_bytes. Nothing is written.
 */
final class DocumentStateTooLargeException extends VaulterException implements ProblemException
{
    public function __construct(
        public readonly int $sizeBytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct(sprintf(
            '[Vaulter] The collaboration state is %d bytes, above the %d-byte limit. Fix: raise vaulter-documents.collaboration.max_state_bytes or split the document. Docs: %s',
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
    #[\Override]
    protected function renderExtras(): array
    {
        return ['context' => ['size_bytes' => $this->sizeBytes, 'max_bytes' => $this->maxBytes]];
    }
}
