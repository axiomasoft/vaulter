<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The document's type is not registered in this installation (its extension was
 * removed or not installed yet). The document stays readable as opaque JSON and can be
 * copied, trashed and purged; editing, exporting and collaborating need the type back.
 */
final class DocumentTypeUnavailableException extends VaulterException implements ProblemException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct(sprintf(
            '[Vaulter] Document type "%s" is not installed. Why: no registered document type has this key, so the content can only be read. Fix: install and register the type again. Docs: %s',
            $type,
            self::docs('document-type-unavailable'),
        ));
    }

    public function problemCode(): string
    {
        return 'document_type_unavailable';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function renderExtras(): array
    {
        return ['context' => ['type' => $this->type]];
    }
}
