<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Registry;

use Override;
use Packman\Attributes\Internal;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** Raised while the application boots, so it is never rendered for a request. */
#[Internal]
final class DuplicateNodeContentLifecycleException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forKind(NodeKind $kind): self
    {
        return new self('[Vaulter] Duplicate node content lifecycle for kind "'.$kind->value.'".');
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
            code: DriveErrorCode::DuplicateRegistration,
            category: ErrorCategory::Internal,
        );
    }
}
