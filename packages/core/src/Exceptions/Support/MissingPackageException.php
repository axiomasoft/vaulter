<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Support;

use Vaulter\Exceptions\VaulterException;

/**
 * A cross-package seam contract (kernel-owned interface, satellite-package-bound
 * implementation) was resolved from the container without its providing package
 * installed. Before this exception, the container's own generic
 * {@see BindingResolutionException} ("Target [...]
 * is not instantiable.") surfaced with no mention of "vaulter" or which composer
 * package to require — a host had to guess (F-P3.3-02 / F-P3.5-01).
 */
final class MissingPackageException extends VaulterException
{
    /**
     * @param class-string $contract
     */
    public static function forContract(string $contract, string $package): self
    {
        return new self(
            sprintf('[Vaulter] [%s] needs the %s package, which is not installed. ', $contract, $package)
            .sprintf('Fix: composer require %s.', $package)
            .' Docs: '.self::docs('missing-package'),
        );
    }
}
