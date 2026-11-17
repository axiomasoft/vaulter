<?php

declare(strict_types=1);

namespace Vaulter\Http\Problems;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Vaulter\CoreModule;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\ModuleId;
use Vaulter\Modules\VaulterModule;

/**
 * Where the problem catalogue takes its sources from: the sealed declarations of the active modules, or, for
 * the reference docs, the declarations of every offered module.
 */
#[Internal]
final class ProblemSources
{
    /**
     * The sources of the active modules. While the graph is invalid or not compiled yet the kernel's own codes
     * still answer (the 503 of an invalid graph is one of them).
     *
     * @return array<string, list<ProblemCodeSource>> module id => sources
     */
    public static function active(Container $app): array
    {
        $kernel = $app->make(Kernel::class);
        if (!$kernel->isValid()) {
            return ['core' => [new CoreProblemCodes]];
        }

        $sources = [];
        foreach ($kernel->compiled()->problems as $module => $classes) {
            foreach ($classes as $class) {
                if (is_a($class, ProblemCodeSource::class, true)) {
                    $sources[$module][] = new $class;
                }
            }
        }

        return $sources;
    }

    /**
     * The sources every offered manifest declares, active or not: an opt-in module documents its codes too.
     *
     * @return array<string, list<ProblemCodeSource>> module id => sources
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
            foreach ($builder->problemSources() as $class) {
                if (is_a($class, ProblemCodeSource::class, true)) {
                    $sources[$builder->module()->value][] = new $class;
                }
            }
        }

        return $sources;
    }
}
