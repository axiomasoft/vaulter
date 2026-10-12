<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Access;

use Vaulter\Exceptions\VaulterException;

/**
 * The host-role driver is missing, unknown, or the open null-driver was selected
 * in production (fail-loud, never silently open).
 */
final class MissingPermissionDriverException extends VaulterException
{
    public static function nullDriverInProduction(string $key): self
    {
        return new self(
            sprintf('[Vaulter] The open null host-role driver [%s] is forbidden in production. ', $key)
            .'Why: it grants every actor full access; running it in prod would expose every node. '
            .'Fix: set config(vaulter.access.permission_driver) to a real driver (database|gate|spatie or an installed bridge) '
            .'or bind your own \Vaulter\Contracts\Access\PermissionDriver. '
            .'Docs: '.self::docs('missing-host-role-driver'),
        );
    }

    /**
     * @param list<string> $known
     */
    public static function unknownDriver(string $key, array $known): self
    {
        return new self(
            sprintf('[Vaulter] Unknown host-role driver [%s]. ', $key)
            .sprintf('Known drivers: [%s]. ', implode(', ', $known))
            .'Fix: register it under config(vaulter.access.permission_drivers) with an FQCN implementing '
            .'\Vaulter\Contracts\Access\PermissionDriver, or pick a known key in config(vaulter.access.permission_driver). '
            .'Docs: '.self::docs('missing-host-role-driver'),
        );
    }
}
