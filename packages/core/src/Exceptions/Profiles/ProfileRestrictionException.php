<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Profiles;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class ProfileRestrictionException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forOperation(string $operation): self
    {
        return new self('[Vaulter] The profile does not allow ['.$operation.']. Why: the effective profile restricts this operation. Fix: use an operation enabled by the drive profile. Docs: '.self::docs('profile-restriction'));
    }

    public function problemCode(): string
    {
        return 'profile_restriction';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
