<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/** The current revision of a file is its content; it goes only with the file. */
#[Api]
final class CannotDeleteHeadRevisionException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forRevision(int|string $revisionId): self
    {
        return new self('[Vaulter] Revision ['.Identity::stringify($revisionId).'] is the current revision of its file and cannot be deleted. '
            .'Fix: restore or upload another revision first, or delete the file.');
    }

    public function problemCode(): string
    {
        return 'cannot_delete_head_revision';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
