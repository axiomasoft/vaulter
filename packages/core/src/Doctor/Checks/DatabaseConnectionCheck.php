<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Doctor\Enums\Severity;
use Throwable;
use Vaulter\Database\VaulterDatabase;

#[Internal]
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
            return [DoctorFinding::error(code: 'core.database.unreachable', message: 'The Vaulter database connection is not reachable.', remediation: 'check vaulter.database.connection and the DB_* settings', evidence: ['exception' => $exception::class])];
        }

        $driver = $connection->getDriverName();
        $evidence = ['connection' => (string) $connection->getName(), 'driver' => $driver];
        if (in_array($driver, self::SUPPORTED, true)) {
            return [DoctorFinding::ok(code: 'core.database.ok', message: 'The Vaulter database is reachable on '.$driver.'.', evidence: $evidence)];
        }

        return [DoctorFinding::at(
            status: $driver === 'sqlite' ? $context->strictStatus() : CheckStatus::Error,
            severity: $driver === 'sqlite' ? $context->strictSeverity() : Severity::Error,
            code: $driver === 'sqlite' ? 'core.database.sqlite' : 'core.database.unsupported_driver',
            message: $driver === 'sqlite' ? 'SQLite is for tests and local development, not for production.' : 'The database driver "'.$driver.'" is not supported.',
            remediation: 'use PostgreSQL, MySQL or MariaDB',
            evidence: $evidence,
        )];
    }
}
