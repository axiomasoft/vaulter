<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Vaulter\CoreModule;
use Vaulter\Kernel;
use Vaulter\Modules\CompiledModules;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * Where the operation catalogue takes its sources from: the sealed declarations of the active modules, or, for
 * the reference, the declarations of every offered module.
 */
#[Internal]
final class OperationSources
{
    /**
     * The sources of the active modules of `$kernel`; none while its graph is invalid (no operation runs then).
     *
     * @return array<string, list<OperationPolicySource>> module id => sources
     */
    public static function active(Kernel $kernel): array
    {
        return $kernel->isValid() ? self::compiled($kernel->compiled()) : [];
    }

    /**
     * @return array<string, list<OperationPolicySource>> module id => sources
     */
    public static function compiled(CompiledModules $compiled): array
    {
        $sources = [];
        foreach ($compiled->operations as $module => $classes) {
            foreach ($classes as $class) {
                if (is_a($class, OperationPolicySource::class, true)) {
                    $sources[$module][] = new $class;
                }
            }
        }

        return $sources;
    }

    /**
     * The sources every offered manifest declares, active or not: an opt-in module documents its operations too.
     *
     * @return array<string, list<OperationPolicySource>> module id => sources
     */
    public static function offered(Container $app): array
    {
        $sources = [];
        foreach (ModuleCatalog::of($app)->offered() as $manifest) {
            if (is_a($manifest, CoreModule::class, true)) {
                $module = new CoreModule;
                $builder = new ModuleBuilder(new ModuleId('core'));
                $module->declareModule($builder);
            } elseif (is_a($manifest, VaulterModule::class, true)) {
                $module = new $manifest;
                $builder = new ModuleBuilder($module->id());
                $module->register($builder);
            } else {
                continue;
            }
            foreach ($builder->operationSources() as $class) {
                if (is_a($class, OperationPolicySource::class, true)) {
                    $sources[$builder->module()->value][] = new $class;
                }
            }
        }

        return $sources;
    }
}
