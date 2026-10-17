<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Access;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

#[Api]
final class UnknownRoleException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function for(string $key, ?int $version = null): self
    {
        return new self(sprintf('[Vaulter] Unknown role [%s]. ', $version === null ? $key : $key.' v'.$version)
            .'Why: grants and permission driver maps accept only the registered roles. '
            .'Fix: use previewer, reader, commenter, writer, organizer or owner. '
            .'Docs: '.self::docs('unknown-role'));
    }

    public function problemCode(): string
    {
        return 'unknown_role';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
