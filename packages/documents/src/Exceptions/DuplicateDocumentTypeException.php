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

/** Raised while the application boots, so it is never rendered for a request. */
#[Api]
final class DuplicateDocumentTypeException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forKey(string $key): self
    {
        return new self(sprintf(
            '[Vaulter] Document type "%s" is registered twice. Fix: register it once, or override it with Documents::types()->replace(). Docs: %s',
            $key,
            self::docs('duplicate-registration'),
        ));
    }

    public function problemCode(): string
    {
        return 'duplicate_registration';
    }

    public function httpStatus(): int
    {
        return 500;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DocumentsErrorCode::DuplicateRegistration,
            category: ErrorCategory::Internal,
        );
    }
}
