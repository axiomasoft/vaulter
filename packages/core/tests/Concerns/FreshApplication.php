<?php

declare(strict_types=1);

namespace Vaulter\Tests\Concerns;

use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Vaulter\Tests\TestCase;

/**
 * Boots a separate application of the core suite with extra config and providers, for a test about what
 * happens at boot (module activation, schedules, routes). The application of the running test is left as it
 * was: the container instance and the facade root are restored. Artisan's `starting()` callbacks are
 * process-wide, so the fresh application gets an artisan of its own, built while only its callbacks exist.
 */
final class FreshApplication
{
    /** Queries run while the last fresh application booted. */
    public static int $queries = 0;

    /**
     * @param array<string, mixed> $config set before the providers register
     * @param list<class-string>   $before providers listed before the Vaulter providers
     * @param list<class-string>   $after  providers listed after them
     */
    public static function boot(array $config = [], array $before = [], array $after = []): Application
    {
        self::$queries = 0;
        $case = new class('freshApplication') extends TestCase
        {
            /** @var array<string, mixed> */
            public static array $config = [];

            /** @var list<class-string> */
            public static array $before = [];

            /** @var list<class-string> */
            public static array $after = [];

            protected function getPackageProviders($app): array
            {
                return [...self::$before, ...parent::getPackageProviders($app), ...self::$after];
            }

            protected function defineEnvironment($app): void
            {
                parent::defineEnvironment($app);
                foreach (self::$config as $key => $value) {
                    $app['config']->set($key, $value);
                }
                $app['events']->listen(QueryExecuted::class, static function (): void {
                    FreshApplication::$queries++;
                });
            }
        };
        $case::$config = $config;
        $case::$before = $before;
        $case::$after = $after;

        $current = Container::getInstance();
        $bootstrappers = new \ReflectionProperty(Artisan::class, 'bootstrappers');
        $kept = $bootstrappers->getValue();
        try {
            $bootstrappers->setValue(null, []);
            $app = $case->createApplication();
            $app->make(ConsoleKernel::class)->all();

            return $app;
        } finally {
            $bootstrappers->setValue(null, $kept);
            Container::setInstance($current);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($current);
        }
    }
}
