<?php

declare(strict_types=1);

namespace Vaulter\Providers;

use Illuminate\Contracts\Foundation\Application;
use Vaulter\Attributes\Internal;
use Vaulter\Events\EventCatalog;
use Vaulter\Events\EventRecorder;
use Vaulter\Events\EventSources;
use Vaulter\Events\EventsReference;
use Vaulter\Modules\ModuleBindings;

#[Internal]
final class EventsRegistrar implements ModuleBindings
{
    public function register(Application $app): void
    {
        // One instance remembers which events it recorded, so recording one twice fails.
        $app->singleton(EventRecorder::class);
        $app->singleton(EventCatalog::class, static fn (Application $app): EventCatalog => new EventCatalog(
            static fn (): array => EventSources::active($app),
            static fn (): array => EventSources::offered($app),
        ));
        // The reference docs list the types of every offered module, an opt-in one included.
        $app->bind(EventsReference::class, static fn (Application $app): EventsReference => new EventsReference(new EventCatalog(
            static fn (): array => EventSources::offered($app),
            static fn (): array => EventSources::offered($app),
        )));
    }

    public function boot(Application $app): void {}
}
