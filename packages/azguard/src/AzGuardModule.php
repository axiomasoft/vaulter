<?php

declare(strict_types=1);

namespace Vaulter\AzGuard;

use Vaulter\Attributes\Internal;
use Vaulter\AzGuard\Contracts\AzGuardGuard;
use Vaulter\AzGuard\Doctor\AzGuardDependencyCheck;
use Vaulter\AzGuard\Providers\BindingsRegistrar;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * The AzGuard bridge module: Auto, because it is an adapter the host still selects explicitly in its
 * config (`vaulter-drive.access.permission_driver = 'azguard'`). It extends the drive's access, so it requires the
 * drive module.
 */
#[Internal]
final class AzGuardModule implements VaulterModule
{
    public function id(): ModuleId
    {
        return new ModuleId('azguard');
    }

    public function version(): string
    {
        return '1.0';
    }

    public function activation(): Activation
    {
        return Activation::Auto;
    }

    public function requires(): array
    {
        return ['core' => '^1.0', 'drive' => '^1.0'];
    }

    public function register(ModuleBuilder $module): void
    {
        $module
            ->config('vaulter-azguard', dirname(__DIR__).'/config/vaulter-azguard.php')
            ->bindings(BindingsRegistrar::class)
            // The explicit guard the host names in `vaulter-azguard.guard`; none configured means the driver builds its own per actor.
            ->defineCapability(AzGuardGuard::class, Cardinality::OptionalSingle)
            ->providesFromConfig(AzGuardGuard::class, 'vaulter-azguard.guard')
            ->slot(DoctorCheck::class, AzGuardDependencyCheck::class, 'azguard.dependency', key: 'azguard.dependency')
            // `vaulter-drive.access.permission_driver = 'azguard'` selects it; the host still picks the lane explicitly.
            ->slot(PermissionDriver::class, AzGuardPermissionDriver::class, 'azguard.permission', key: 'azguard');
    }
}
