<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\Apps;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Testing\Concerns\FreshApplication;
use Vaulter\Integration\Tests\TestCase;

/**
 * One application of the install matrix (I6, I15): the Vaulter providers of a downward-closed set of packages (D6). A set is
 * the package `$top` and everything it needs; the module it adds over its `$parent` set is the one the matrix switches off
 * and compares with that parent.
 */
final readonly class AppSet
{
    /**
     * @param list<string>         $providers   service providers of the set, in registration order
     * @param list<string>         $modules     the ids of the modules the set offers, a module after the ones it requires
     * @param array<string, mixed> $on          config that makes the top module active (an OptIn module needs its switch)
     * @param ?string              $requiredOff a module the top requires and the matrix switches off to invalidate the graph (null: the top needs only the core)
     */
    public function __construct(
        public string $id,
        public array $providers,
        public array $modules,
        public string $top,
        public ?string $parent,
        public array $on = [],
        public ?string $requiredOff = null,
    ) {}

    /** @return list<string> the module ids, sorted */
    public function sortedModules(): array
    {
        $modules = $this->modules;
        sort($modules);

        return $modules;
    }

    /** The config that switches the top module off. @return array<string, mixed> */
    public function off(): array
    {
        return ['vaulter.modules' => [$this->top => false]];
    }

    /**
     * A test case whose application carries exactly the providers of the set and the environment of the running suite (database
     * connection, ids, rate limiters). {@see FreshApplication::boot()} takes both from it.
     *
     * @param array<string, mixed> $config
     */
    public function testCase(array $config = []): OrchestraTestCase
    {
        $case = new class('appSet') extends TestCase
        {
            /** @var list<string> */
            public static array $providers = [];

            /** @var array<string, mixed> */
            public static array $config = [];

            /** @param ApplicationContract $app @return list<string> */
            protected function getPackageProviders($app): array
            {
                return self::$providers;
            }

            protected function defineEnvironment($app): void
            {
                parent::defineEnvironment($app);
                foreach (self::$config as $key => $value) {
                    $app['config']->set($key, $value);
                }
            }
        };
        $case::$providers = $this->providers;
        $case::$config = $config;

        return $case;
    }
}
