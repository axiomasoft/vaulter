<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Exceptions\VaulterException;

/**
 * A capability or a slot handler was asked for but no active module provides it — an integration
 * error, not a client one.
 */
final class MissingExtensionException extends VaulterException
{
    public static function capability(string $contract): self
    {
        return new self(sprintf(
            '[Vaulter] No active module provides %s. '
            .'Why: the module that defines or provides it is not installed, not active, or its config key is empty. '
            .'Fix: install and activate the module (vaulter:modules lists them), or check has() first.',
            $contract,
        ));
    }

    public static function notSingle(string $contract): self
    {
        return new self(sprintf(
            '[Vaulter] %s takes many providers. Why: require() resolves a single capability. Fix: use all().',
            $contract,
        ));
    }

    public static function handler(string $slot, string $key): self
    {
        return new self(sprintf(
            '[Vaulter] No active module contributes "%s" to %s. '
            .'Why: the key is not declared by any active module. '
            .'Fix: list the keys with handlerKeys() or activate the contributing module.',
            $key,
            $slot,
        ));
    }
}
