<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleCatalog;
use Vaulter\Modules\PluginModuleBridge;

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
        $catalog = ModuleCatalog::of($app);
        $sources = [];
        foreach ($catalog->offered() as $manifest) {
            $builder = PluginModuleBridge::buildOffered($manifest, $catalog->pluginSpec($manifest));
            if ($builder === null) {
                continue;
            }
            if ($builder->eventSources() !== []) {
                $sources[$builder->module()->value] = $builder->eventSources();
            }
        }
        ksort($sources, SORT_STRING);

        return $sources;
    }
}
