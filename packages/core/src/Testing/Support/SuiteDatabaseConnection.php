<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Packman\Attributes\Internal;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\Api\Groups;
use RuntimeException;
use Vaulter\Schema\ModuleSchemas;

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
        $token = self::envString('TEST_TOKEN');
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
    /**
     * The migration directories of every offered module, as its manifest declares them: a package's TestCase migrates
     * these, never a path of core alone (a module's tables are created by the migrations of its own package).
     *
     * @return list<string>
     */
    public static function migrationPaths(Application $app): array
    {
        return $app->make(ModuleSchemas::class)->migrationPaths();
    }

    public static function engine(): string
    {
        $driver = Env::get('DB_CONNECTION');

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
                Assert::markTestSkipped("Meant for the {$wanted} engine; this run is on {$engine}.");
            }
        }
    }

    /** @return array<string, string|bool|null> */
    public static function resolve(): array
    {
        $driver = self::envString('DB_CONNECTION');

        if ($driver === '' || $driver === 'sqlite') {
            // Foreign keys on, as on the server engines: without them a cascade or a dangling reference passes here only.
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Unsupported test DB_CONNECTION ['.$driver.']; use sqlite, pgsql, mysql or mariadb.');
        }

        $database = self::envString('DB_DATABASE', 'vaulter_test');

        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Test database must end with _test: '.$database);
        }

        // A parallel run (scripts/test-db.sh) gives every worker its own database, `<base>_w<token>_test`,
        // created by the script; the `_test` suffix stays part of every name.
        $token = self::envString('TEST_TOKEN');
        if (self::envString('VAULTER_TEST_DB_PER_PROCESS') === '1' && preg_match('/^[0-9]+$/', $token) === 1) {
            $database = substr($database, 0, -strlen('_test')).'_w'.$token.'_test';
        }

        if ($driver !== 'pgsql') {
            return [
                'driver' => $driver,
                'host' => self::envString('DB_HOST', '127.0.0.1'),
                'port' => self::envString('DB_PORT', '3306'),
                'database' => $database,
                'username' => self::envString('DB_USERNAME', 'vaulter'),
                'password' => self::envString('DB_PASSWORD'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ];
        }

        return [
            'driver' => 'pgsql',
            'host' => self::envString('DB_HOST', '127.0.0.1'),
            'port' => self::envString('DB_PORT', '5432'),
            'database' => $database,
            'username' => self::envString('DB_USERNAME', 'vaulter'),
            'password' => self::envString('DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    private static function envString(string $key, string $default = ''): string
    {
        $raw = Env::get($key);

        return is_scalar($raw) ? (string) $raw : $default;
    }
}
