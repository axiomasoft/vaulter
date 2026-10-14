<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Vaulter\Doctor\Checks\AccessDriverCheck;
use Vaulter\Doctor\Checks\RoutesAuthCheck;
use Vaulter\Doctor\DoctorRegistry;

/** Tags the drive doctor checks still located in core; the core and webhooks checks are slot contributions. */
final class DoctorRegistrar
{
    // P3.3 moves to DriveModule (D39).
    public function register(Application $app): void
    {
        $app->tag([
            RoutesAuthCheck::class,
            AccessDriverCheck::class,
        ], DoctorRegistry::TAG);
    }
}
