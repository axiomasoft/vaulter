<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Documents\Errors\DocumentsErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class UnknownExportFormatException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'unknown_export_format';

    private const int HTTP_STATUS = 422;

    /**
     * @param list<string> $allowed
     */
    public static function forFormat(string $format, array $allowed): self
    {
        $list = implode(', ', $allowed);

        $exception = new self(
            sprintf('[Vaulter] Unknown export format [%s]. ', $format)
            .sprintf('Why: no exporter renders it for this document type (available: [%s]). ', $list)
            .'Fix: register one via config(vaulter-documents.exporters) or Documents::exporters()->register(). '
            .'Docs: '.self::docs('unknown-export-format'),
        );
        $exception->allowed = $allowed;

        return $exception;
    }

    /** @var list<string> */
    private array $allowed = [];

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
    public function problemDetails(): array
    {
        return ['allowed' => $this->allowed];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DocumentsErrorCode::UnknownExportFormat,
            category: ErrorCategory::Validation,
            details: ['allowed' => $this->allowed],
        );
    }
}
