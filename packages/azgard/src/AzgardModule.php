<?php

declare(strict_types=1);

namespace Vaulter\Azgard;

use Vaulter\Azgard\Contracts\AzgardGuard;
use Vaulter\Azgard\Doctor\AzgardDependencyCheck;
use Vaulter\Azgard\Providers\BindingsRegistrar;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Modules\Activation;
use Vaulter\Modules\Cardinality;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * The AzGuard bridge module (D22): Auto, because it is an adapter the host still selects explicitly in its
 * config (`vaulter.access.permission_driver = 'azgard'`). It extends the drive's access, so it requires the
 * drive module.
 */
final class AzgardModule implements VaulterModule
{
    public function id(): ModuleId
    {
        return new ModuleId('azgard');
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
            ->config('vaulter-azgard', dirname(__DIR__).'/config/vaulter-azgard.php')
            ->bindings(BindingsRegistrar::class)
            // The explicit guard the host names in `vaulter-azgard.guard`; none configured means the driver builds its own per actor.
            ->defineCapability(AzgardGuard::class, Cardinality::OptionalSingle)
            ->providesFromConfig(AzgardGuard::class, 'vaulter-azgard.guard')
            ->slot(DoctorCheck::class, AzgardDependencyCheck::class, 'azgard.dependency', key: 'azgard.dependency')
            // `vaulter.access.permission_driver = 'azgard'` selects it; the host still picks the lane explicitly.
            ->slot(PermissionDriver::class, AzgardPermissionDriver::class, 'azgard.permission', key: 'azgard');
    }
}
