<?php

declare(strict_types=1);

namespace Vaulter\AzGuard\Tests\Stubs;

use Vaulter\AzGuard\Contracts\AzGuardGuard;

/**
 * The host's explicit guard: `vaulter-azguard.guard` names its class and the container hands out this very
 * object (the configured class is what the capability resolves).
 */
final class HostGuard
{
    public static function bind(AzGuardGuard $guard): void
    {
        config()->set('vaulter-azguard.guard', $guard::class);
        app()->instance($guard::class, $guard);
    }

    public static function clear(): void
    {
        config()->set('vaulter-azguard.guard', null);
    }
}
