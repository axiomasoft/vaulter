<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A document content write carried an expected state version the working state no
 * longer has: another write landed first. Content has its own validator, so a
 * rename or move of the node never invalidates it (and vice versa).
 */
#[Api]
final class StaleDocumentStateException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(
        public readonly int $expected,
        public readonly int $actual,
        private readonly ?string $nodeId = null,
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
     * Only the node: the current state version would let a caller rebuild the content tag
     * without reading the content it is about to replace.
     *
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return $this->nodeId === null ? [] : ['node_id' => $this->nodeId];
    }
}
