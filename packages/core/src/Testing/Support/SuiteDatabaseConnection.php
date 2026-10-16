<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\Api\Groups;
use RuntimeException;
use Vaulter\Attributes\Internal;

/**
 * The one place every package's TestCase decides which database a suite runs on.
 *
 * Default: in-memory SQLite. Opt INTO a server with DB_CONNECTION=pgsql|mysql|mariadb plus the
 * DB_* coordinates. Anything else is an error, never a silent SQLite run that would let a
 * requested MySQL/MariaDB lane pass on the wrong engine, and a server database must end in
 * `_test` because the suites migrate it fresh.
 */
#[Internal]
final class SuiteDatabaseConnection
{
    /**
     * Parallel workers share the Testbench skeleton, whose `storage/framework/testing/disks` is wiped by every
     * `Storage::fake()`; a worker (TEST_TOKEN set) gets a storage directory of its own, removed with the run's
     * temporary files. A serial run keeps the skeleton's storage.
     */
    public static function isolateStorage(Application $app): void
    {
        $token = (string) env('TEST_TOKEN', '');
        if (preg_match('/^[0-9]+$/', $token) !== 1) {
            return;
        }

        $storage = sys_get_temp_dir().'/vaulter-storage-'.getmypid();
        foreach (['app', 'framework/cache', 'framework/sessions', 'framework/views', 'framework/testing', 'logs'] as $directory) {
            if (!is_dir($storage.'/'.$directory)) {
                mkdir($storage.'/'.$directory, 0o775, true);
            }
        }
        $app->useStoragePath($storage);
        register_shutdown_function(static function () use ($storage): void {
            (new Filesystem)->deleteDirectory($storage);
        });
    }

    /** The engine the suite runs on, decided by the environment alone (no application needed): sqlite|pgsql|mysql|mariadb. */
    public static function engine(): string
    {
        $driver = env('DB_CONNECTION');

        return is_string($driver) && $driver !== '' ? $driver : 'sqlite';
    }

    /**
     * Skips a test that cannot say anything on this engine BEFORE the application and its schema exist. A test names the engines it
     * is meant for with the groups `engine:pgsql`, `engine:mysql` (that engine only; `mariadb` counts as mysql) or `engine:server`
     * (any server engine, never SQLite). A skip from the test body would come after the schema was migrated for nothing.
     *
     * Call it first in every suite TestCase::defineDatabaseMigrations(): the application is booted there (a test's own hooks and
     * Facades still work when the skip unwinds), and no migration has run yet. A skip from setUp() before the boot breaks them.
     */
    public static function skipOnForeignEngine(TestCase $test): void
    {
        $engine = self::engine();
        $family = $engine === 'mariadb' ? 'mysql' : $engine;

        foreach ((new Groups)->groups($test::class, $test->name()) as $group) {
            if (!str_starts_with($group, 'engine:')) {
                continue;
            }
            $wanted = substr($group, strlen('engine:'));
            if ($wanted === 'server' ? $family === 'sqlite' : $wanted !== $family) {
                $test->markTestSkipped("Meant for the {$wanted} engine; this run is on {$engine}.");
            }
        }
    }

    /** @return array<string, string|bool|null> */
    public static function resolve(): array
    {
        $driver = env('DB_CONNECTION');

        if ($driver === null || $driver === '' || $driver === 'sqlite') {
            // Foreign keys on, as on the server engines: without them a cascade or a dangling reference passes here only.
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Unsupported test DB_CONNECTION ['.$driver.']; use sqlite, pgsql, mysql or mariadb.');
        }

        $database = (string) env('DB_DATABASE', 'vaulter_test');

        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Test database must end with _test: '.$database);
        }

        // A parallel run (scripts/test-db.sh) gives every worker its own database, `<base>_w<token>_test`,
        // created by the script; the `_test` suffix stays part of every name.
        $token = (string) env('TEST_TOKEN', '');
        if (env('VAULTER_TEST_DB_PER_PROCESS') === '1' && preg_match('/^[0-9]+$/', $token) === 1) {
            $database = substr($database, 0, -strlen('_test')).'_w'.$token.'_test';
        }

        if ($driver !== 'pgsql') {
            return [
                'driver' => $driver,
                'host' => (string) env('DB_HOST', '127.0.0.1'),
                'port' => (string) env('DB_PORT', '3306'),
                'database' => $database,
                'username' => (string) env('DB_USERNAME', 'vaulter'),
                'password' => (string) env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ];
        }

        return [
            'driver' => 'pgsql',
            'host' => (string) env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '5432'),
            'database' => $database,
            'username' => (string) env('DB_USERNAME', 'vaulter'),
            'password' => (string) env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }
}
