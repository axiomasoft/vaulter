<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Internal;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleBuilder;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\VaulterModule;

/**
 * Where the event catalogue takes its enums from: the sealed declarations of the active modules, or the
 * declarations of every offered manifest (the reference docs, and stored values of a module switched off).
 */
#[Internal]
final readonly class EventSources
{
    /**
     * The enums of the active modules; none while the graph is invalid or not compiled yet.
     *
     * @return array<string, list<class-string>> module id => enums
     */
    public static function active(Container $app): array
    {
        $kernel = $app->make(Kernel::class);

        return $kernel->isValid() ? $kernel->compiled()->events : [];
    }

    /**
     * The enums every offered manifest declares, active or not.
     *
     * @return array<string, list<class-string>> module id => enums
     */
    public static function offered(Container $app): array
    {
        $sources = [];
        foreach (ModuleCatalog::of($app)->offered() as $manifest) {
            if (!is_a($manifest, VaulterModule::class, true)) {
                continue;
            }
            $module = new $manifest;
            $builder = new ModuleBuilder($module->id());
            $module->register($builder);
            if ($builder->eventSources() !== []) {
                $sources[$builder->module()->value] = $builder->eventSources();
            }
        }
        ksort($sources, SORT_STRING);

        return $sources;
    }
}
