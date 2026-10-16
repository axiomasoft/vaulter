<?php

declare(strict_types=1);

namespace Vaulter\Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;

/**
 * Listed first among the providers of a {@see FreshApplication}, so its spies are in place before any other provider
 * registers: a query or a container resolution made by `register()` of a provider is counted too.
 */
final class FreshApplicationSpy extends ServiceProvider
{
    public function register(): void
    {
        $this->app['events']->listen(QueryExecuted::class, static function (): void {
            FreshApplication::$queries++;
        });
        $this->app->resolving(static function (mixed $object): void {
            FreshApplication::$resolved[] = get_debug_type($object);
        });
    }
}
