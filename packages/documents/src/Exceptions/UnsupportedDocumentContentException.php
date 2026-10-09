<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * An exporter was handed content of a shape it does not render. Thrown by the exporter
 * itself, so a custom exporter can narrow the same way the built-in ones do.
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
            .'Fix: list only document types with this content shape in the exporter\'s documentTypes(). '
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
