<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Registry;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * An extension registry was changed after the application booted. Registries are
 * filled while providers register and boot, then frozen, so every request (and every
 * Octane worker) sees the same set.
 */
final class RegistryFrozenException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    public static function forRegistry(string $registry): self
    {
        return new self(
            sprintf('[Vaulter] The %s registry is frozen. ', $registry)
            .'Why: registrations are accepted only until the application has booted. '
            .'Fix: register from a service provider (config, a container tag, or register() before booted). '
            .'Docs: '.self::docs('registry-frozen'),
        );
    }

    public function problemCode(): string
    {
        return 'registry_frozen';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
