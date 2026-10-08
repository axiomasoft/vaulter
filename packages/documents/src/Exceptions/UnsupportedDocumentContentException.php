<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * Lives in CORE, next to {@see UnknownExportFormatException}: the contract it guards
 * ({@see DocumentExporter}) is a core seam, and a core-only
 * host registering its own exporter through configuration key vaulter-documents.exporters must be able to
 * throw it without pulling in vaulter-documents.
 */
final class UnsupportedDocumentContentException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'invalid_document_content';

    private const int HTTP_STATUS = 422;

    public static function forExporter(string $exporterFormat, string $expected, string $actual): self
    {
        return new self(
            sprintf('[Vaulter] The [%s] exporter cannot render this content. ', $exporterFormat)
            .sprintf('Why: it only understands [%s], but received [%s]. ', $expected, $actual)
            .'Fix: only route nodes whose resolved handler produces the expected content shape to '
            .'this exporter (config(vaulter-documents.exporters)). '
            .'Docs: '.self::docs('unsupported-document-content'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function renderExtras(): array
    {
        return ['reason' => 'unsupported_content'];
    }
}
