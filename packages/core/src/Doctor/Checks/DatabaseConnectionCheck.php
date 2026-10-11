<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Throwable;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorSeverity;

final readonly class DatabaseConnectionCheck implements DoctorCheck
{
    private const array SUPPORTED = ['pgsql', 'mysql', 'mariadb'];

    public function __construct(private VaulterDatabase $database) {}

    public function key(): string
    {
        return 'core.database.connection';
    }

    public function title(): string
    {
        return 'Database connection';
    }

    public function run(DoctorContext $context): array
    {
        try {
            $connection = $this->database->connection();
            $connection->selectOne('select 1');
        } catch (Throwable $exception) {
            // The driver message can hold the DSN with credentials.
            return [DoctorFinding::error(code: 'core.database.unreachable', message: 'The Vaulter database connection is not reachable.', hint: 'check vaulter.database.connection and the DB_* settings', evidence: ['exception' => $exception::class])];
        }

        $driver = $connection->getDriverName();
        $evidence = ['connection' => (string) $connection->getName(), 'driver' => $driver];
        if (in_array($driver, self::SUPPORTED, true)) {
            return [DoctorFinding::ok(code: 'core.database.ok', message: 'The Vaulter database is reachable on '.$driver.'.', evidence: $evidence)];
        }

        return [DoctorFinding::at(
            severity: $driver === 'sqlite' ? $context->strictSeverity() : DoctorSeverity::Error,
            code: $driver === 'sqlite' ? 'core.database.sqlite' : 'core.database.unsupported_driver',
            message: $driver === 'sqlite' ? 'SQLite is for tests and local development, not for production.' : 'The database driver "'.$driver.'" is not supported.',
            hint: 'use PostgreSQL, MySQL or MariaDB',
            evidence: $evidence,
        )];
    }
}
