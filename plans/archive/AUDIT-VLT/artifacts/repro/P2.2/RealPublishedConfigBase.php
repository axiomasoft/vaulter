<?php

declare(strict_types=1);

namespace Vaulter\Tests\P22Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * P2.2 C5 repro — REAL host-publish simulation. A vaulter.php config file is placed
 * on disk at the Testbench skeleton's config_path() BEFORE the application boots (see
 * setUpBeforeClass()/tearDownAfterClass() below), so Laravel's real LoadConfiguration
 * bootstrapper (which ALWAYS runs before any ServiceProvider::register(), per the
 * actual Laravel bootstrap sequence — confirmed by CreatesApplication::
 * resolveApplicationConfiguration() at line ~413 running BEFORE RegisterProviders at
 * line ~534) loads it into config('vaulter') exactly like it would for a real host
 * that ran `php artisan vendor:publish --tag=vaulter-config` and edited one key.
 *
 * (Earlier attempts using defineEnvironment() to inject the override were a FALSE
 * simulation: Testbench calls defineEnvironment() at line ~543, AFTER
 * RegisterProviders at line ~534 — i.e. AFTER all package providers already merged
 * their config. That ordering quirk is Testbench-specific test-hook machinery, not
 * representative of a real Laravel boot, and produced a misleading "keys vanish"
 * result that was an artifact of the harness, not the package.)
 */
abstract class RealPublishedConfigBase extends OrchestraTestCase
{
    private static function skeletonConfigPath(): string
    {
        return dirname(__DIR__, 5).'/vendor/orchestra/testbench-core/laravel/config/vaulter.php';
    }

    /**
     * Runs BEFORE this class's per-test setUp() — i.e. before Testbench boots the
     * application and runs LoadConfiguration — and is scoped to THIS test class
     * only, so it cannot leak into other repro files collected in the same run.
     */
    public static function setUpBeforeClass(): void
    {
        $published = require dirname(__DIR__, 5).'/packages/core/config/vaulter.php';
        $published['models']['node_comment'] = 'App\\Models\\HostNodeComment';

        file_put_contents(
            self::skeletonConfigPath(),
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($published, true).";\n",
        );

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::skeletonConfigPath());

        parent::tearDownAfterClass();
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            VaulterServiceProvider::class,
            VaulterMediaServiceProvider::class,
            VaulterDriveServiceProvider::class,
            VaulterDocumentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
