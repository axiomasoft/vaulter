<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A collaboration store carried a binary Yjs state above
 * vaulter-collaboration.max_state_bytes. Nothing is written.
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
            '[Vaulter] The collaboration state is %d bytes, above the %d-byte limit. Fix: raise vaulter-collaboration.max_state_bytes or split the document. Docs: %s',
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
}
