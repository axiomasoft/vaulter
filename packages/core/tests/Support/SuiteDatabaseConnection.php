<?php

declare(strict_types=1);

namespace Vaulter\Tests\Support;

use RuntimeException;

/**
 * The one place every package's TestCase decides which database a suite runs on.
 *
 * Default: in-memory SQLite. Opt INTO a server with DB_CONNECTION=pgsql|mysql|mariadb plus the
 * DB_* coordinates. Anything else is an error, never a silent SQLite run that would let a
 * requested MySQL/MariaDB lane pass on the wrong engine, and a server database must end in
 * `_test` because the suites migrate it fresh.
 */
final class SuiteDatabaseConnection
{
    /** @return array<string, string|bool|null> */
    public static function resolve(): array
    {
        $driver = env('DB_CONNECTION');

        if ($driver === null || $driver === '' || $driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Unsupported test DB_CONNECTION ['.(is_scalar($driver) ? (string) $driver : gettype($driver)).']; use sqlite, pgsql, mysql or mariadb.');
        }

        $database = (string) env('DB_DATABASE', 'vaulter_test');

        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Test database must end with _test: '.$database);
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
