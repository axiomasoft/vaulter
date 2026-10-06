<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Profiles;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * Thrown when a storage panel is looked up by an id that is not registered in
 * the ProfileRegistry (configuration key vaulter.profiles.providers plus the default panel).
 */
final class UnknownProfileException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'unknown_profile';

    private const int HTTP_STATUS = 422;

    /**
     * @param list<string> $known
     */
    public static function forName(string $name, array $known): self
    {
        $available = $known === [] ? '(none)' : implode(', ', $known);

        return new self(
            "Unknown Vaulter storage panel [{$name}]. Registered: {$available}. "
            .'Fix: register a ProfileProvider FQCN under configuration key vaulter.profiles.providers.',
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
}
