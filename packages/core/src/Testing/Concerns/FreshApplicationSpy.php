<?php

declare(strict_types=1);

namespace Vaulter\Testing\Concerns;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;
use Vaulter\Attributes\Internal;

/**
 * Listed first among the providers of a {@see FreshApplication}, so its spies are in place before any other provider
 * registers: a query or a container resolution made by `register()` of a provider is counted too.
 */
#[Internal]
final class FreshApplicationSpy extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(Dispatcher::class)->listen(QueryExecuted::class, static function (): void {
            FreshApplication::$queries++;
        });
        $this->app->resolving(static function (mixed $object): void {
            FreshApplication::$resolved[] = get_debug_type($object);
        });
    }
}
