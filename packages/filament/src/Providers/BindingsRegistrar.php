<?php

declare(strict_types=1);

namespace Vaulter\Filament\Providers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Filament\PackmanFilamentServiceProvider;
use Vaulter\Configuration\CompiledOptionsCache;
use Vaulter\Filament\Configuration\FilamentOptions;
use Vaulter\Modules\ModuleBindings;

/** The container wiring of the Filament module: its config reader. */
#[Internal]
final class BindingsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        $app->register(PackmanFilamentServiceProvider::class);
        $app->bind(FilamentOptions::class, static fn (Container $app): FilamentOptions => CompiledOptionsCache::resolve($app, 'vaulter-filament', static fn (array $raw): FilamentOptions => FilamentOptions::fromArray($raw)));
    }

    public function boot(Application $app): void
    {
        // The surface gate admits an authorizable panel actor. Snapshot rows and all mutations
        // are independently authorized by the drive handles; no Eloquent write is exposed.
        $gate = $app->make(Gate::class);
        foreach ([
            'drive' => ['view_any', 'view'],
            'node' => ['view_any', 'rename', 'move', 'trash', 'restore', 'force_delete'],
            'grant' => ['view_any', 'create', 'revoke'],
            'share' => ['view_any', 'create', 'revoke', 'rotate'],
            'activity' => ['view_any', 'view'],
        ] as $resource => $abilities) {
            foreach ($abilities as $ability) {
                $key = 'vaulter.'.$resource.'.'.$ability;
                if (!$gate->has($key)) {
                    $gate->define($key, static fn (object $actor): bool => $actor instanceof Authorizable);
                }
            }
        }
    }
}
