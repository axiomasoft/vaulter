<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * The stored content holds parts its type no longer accepts (the nodes of a disabled
 * editor extension, say), so it was read with those parts dropped. Saving over it
 * would delete them for good; the document is read-only until the host enables the
 * missing extensions again.
 */
final class DocumentContentDegradedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public function __construct(public readonly string $type)
    {
        parent::__construct(sprintf(
            '[Vaulter] The content of this "%s" document cannot be changed. Why: it contains parts the document type does not accept any more, and a save would drop them. Fix: enable the missing editor extensions (vaulter-documents.tiptap.*), or restore a revision that does not use them. Docs: %s',
            $type,
            self::docs('document-content-degraded'),
        ));
    }

    public function problemCode(): string
    {
        return 'document_content_degraded';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * @return array<string, mixed>
     */
    public function problemDetails(): array
    {
        return ['context' => ['type' => $this->type]];
    }
}
