<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A document content write carried an expected state version the working state no
 * longer has: another write landed first. Content has its own validator, so a
 * rename or move of the node never invalidates it (and vice versa).
 */
final class StaleDocumentStateException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(
        public readonly int $expected,
        public readonly int $actual,
    ) {
        parent::__construct(sprintf(
            '[Vaulter] The document content changed (expected state version %d, current %d); reload it before retrying. Docs: %s',
            $expected,
            $actual,
            self::docs('document-state-mismatch'),
        ));
    }

    public function problemCode(): string
    {
        return 'document_state_mismatch';
    }

    public function httpStatus(): int
    {
        return 412;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['context' => ['expected' => $this->expected, 'actual' => $this->actual]];
    }
}
