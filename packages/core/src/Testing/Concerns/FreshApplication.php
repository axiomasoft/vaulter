<?php

declare(strict_types=1);

namespace Vaulter\Testing\Concerns;

use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Orchestra\Testbench\TestCase;
use Pest\TestSuite;
use ReflectionMethod;
use RuntimeException;
use Vaulter\Attributes\Internal;

/**
 * Boots a separate application of the running suite with extra config and providers, for a test about what happens
 * at boot (module activation, schedules, routes). Its providers and environment come from the test case that calls it
 * (the running Pest test, or $case). The application of the running test is left as it was: the container instance and
 * the facade root are restored. Artisan's `starting()` callbacks are process-wide, so the fresh application gets an
 * artisan of its own, built while only its callbacks exist.
 */
#[Internal]
final class FreshApplication
{
    /** Queries run while the last fresh application booted. */
    public static int $queries = 0;

    /** @var list<string> What the container built (by class) while the last fresh application booted; the spy provider records it. */
    public static array $resolved = [];

    /**
     * @param array<string, mixed> $config set before the providers register
     * @param list<class-string>   $before providers listed before the Vaulter providers
     * @param list<class-string>   $after  providers listed after them
     * @param ?TestCase            $case   the test case whose providers and environment the application takes; default: the running Pest test
     */
    public static function boot(array $config = [], array $before = [], array $after = [], ?TestCase $case = null): Application
    {
        $base = $case ?? TestSuite::getInstance()->test;
        if (!$base instanceof TestCase) {
            throw new RuntimeException('FreshApplication::boot() needs an Orchestra Testbench test case: call it from a test or pass $case');
        }
        self::$queries = 0;
        self::$resolved = [];
        $fresh = new class('freshApplication') extends TestCase
        {
            public static ?TestCase $base = null;

            /** @var array<string, mixed> */
            public static array $config = [];

            /** @var list<class-string> */
            public static array $before = [];

            /** @var list<class-string> */
            public static array $after = [];

            protected function getPackageProviders($app): array
            {
                $base = self::$base ?? throw new RuntimeException('FreshApplication has no base test case');
                /** @var array<int, class-string> $providers */
                $providers = (new ReflectionMethod($base, 'getPackageProviders'))->invoke($base, $app);

                return [FreshApplicationSpy::class, ...self::$before, ...$providers, ...self::$after];
            }

            protected function defineEnvironment($app): void
            {
                $base = self::$base ?? throw new RuntimeException('FreshApplication has no base test case');
                (new ReflectionMethod($base, 'defineEnvironment'))->invoke($base, $app);
                foreach (self::$config as $key => $value) {
                    $app['config']->set($key, $value);
                }
            }
        };
        $fresh::$base = $base;
        $fresh::$config = $config;
        $fresh::$before = $before;
        $fresh::$after = $after;

        $current = Container::getInstance();
        $bootstrappers = new \ReflectionProperty(Artisan::class, 'bootstrappers');
        $kept = $bootstrappers->getValue();
        try {
            $bootstrappers->setValue(null, []);
            $app = $fresh->createApplication();
            $app->make(ConsoleKernel::class)->all();

            return $app;
        } finally {
            $bootstrappers->setValue(null, $kept);
            Container::setInstance($current);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($current instanceof Application ? $current : null);
        }
    }
}
