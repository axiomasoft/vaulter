<?php

declare(strict_types=1);

namespace Vaulter\Tests\P22Repro;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

/**
 * P2.2 C3/C13 repro — config:cache safety smoke.
 *
 * NOTE on methodology: the item's Validation asks for `vendor:publish --list`/
 * `config:cache` output via the literal `vendor/bin/testbench` CLI binary against
 * the root testbench.yaml. That CLI invocation is BROKEN in this checkout
 * independently of config:cache — even `vendor/bin/testbench package:discover` (which
 * does no config caching at all) throws the exact same
 * "CAS storage driver not configured" RuntimeException during its OWN boot, on
 * every invocation, cached or not (see the finding's "Не проверено" section for the
 * literal transcripts of `config:cache`, `config:clear` and `package:discover`, all
 * three failing identically). This is a pre-existing CLI/bootstrap-cache
 * environment issue (root `vendor/orchestra/testbench-core/laravel/bootstrap/cache/
 * packages.php` only lists `axioma-studio/vaulter-core`'s provider from composer
 * discovery, not media/drive/documents — a stale or partial discovery cache), NOT
 * something this read-only audit may fix (D2). This repro instead drives
 * `Artisan::call('config:cache')` inside a Testbench TestCase — the SAME
 * bootstrapping infrastructure the project's own suite uses per
 * `.claude/rules/tests.md` ("Инфра — Orchestra Testbench") — which boots cleanly and
 * lets C3/C13 be verified by a real command run + real container resolution.
 */
abstract class ConfigCacheSmokeBase extends OrchestraTestCase
{
    private static function cachedConfigPath(): string
    {
        return dirname(__DIR__, 5).'/vendor/orchestra/testbench-core/laravel/bootstrap/cache/config.php';
    }

    /**
     * A GUARANTEED cleanup, independent of whether the application can still boot
     * (Artisan::call('config:clear') needs a working boot — if the cached-config
     * reboot this test exercises leaves the app unable to boot, config:clear itself
     * would fail too, permanently poisoning every OTHER Testbench-based test in this
     * checkout that shares the same default_skeleton_path() bootstrap/cache
     * directory. Direct unlink() has no such dependency.
     */
    public static function tearDownAfterClass(): void
    {
        @unlink(self::cachedConfigPath());

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
        $app['config']->set('vaulter.features.reliability', true);
        $app['config']->set('vaulter.features.activity', true);
        $app['config']->set('vaulter.features.comments', true);
    }
}
