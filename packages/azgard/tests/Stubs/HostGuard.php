<?php

declare(strict_types=1);

namespace Vaulter\Azgard\Tests\Stubs;

use Vaulter\Azgard\Contracts\AzgardGuard;

/**
 * The host's explicit guard: `vaulter-azgard.guard` names its class and the container hands out this very
 * object (the configured class is what the capability resolves).
 */
final class HostGuard
{
    public static function bind(AzgardGuard $guard): void
    {
        config()->set('vaulter-azgard.guard', $guard::class);
        app()->instance($guard::class, $guard);
    }

    public static function clear(): void
    {
        config()->set('vaulter-azgard.guard', null);
    }
}
