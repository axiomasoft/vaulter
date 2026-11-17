<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Nodes;

use Override;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;
use Vaulter\Drive\Errors\DriveErrorCode;
use Vaulter\Drive\Models\Node;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/** The original parent is gone or trashed and no destination was given. */
#[Api]
final class RestoreDestinationRequiredException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    /** @internal */
    #[Internal]
    public static function forNode(Node $node): self
    {
        return new self(
            "[Vaulter] Node [{$node->id}] cannot return to its original parent. "
            .'Why: the parent no longer exists or is in the trash, and a restore never moves a node silently. '
            .'Fix: pass an explicit destination folder in the restore options. '
            .'Docs: '.self::docs('restore-destination-required'),
        );
    }

    public function problemCode(): string
    {
        return 'restore_destination_required';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: DriveErrorCode::RestoreDestinationRequired,
            category: ErrorCategory::Conflict,
        );
    }
}
