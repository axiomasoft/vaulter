<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Packman\Attributes\Internal;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use Packman\Doctor\Enums\Severity;
use PDO;
use Throwable;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;

/**
 * Validates PgBouncer transaction-pooling compatibility on PostgreSQL connections:
 * `PDO::ATTR_EMULATE_PREPARES => false` and `max_prepared_statements` (>= 200 recommended).
 */
#[Internal]
final readonly class PgBouncerDoctorCheck implements DoctorCheck
{
    private const int RECOMMENDED_MAX_PREPARED_STATEMENTS = 200;

    public function __construct(
        private Repository $config,
        private DatabaseOptions $databaseOptions,
        private VaulterDatabase $database,
    ) {}

    public function key(): string
    {
        return 'core.database.pgbouncer';
    }

    public function title(): string
    {
        return 'PostgreSQL / PgBouncer prepared statements';
    }

    public function run(DoctorContext $context): array
    {
        $connectionName = $this->databaseOptions->connection ?? $this->text($this->config->get('database.default'), 'sqlite');
        $rawConfig = $this->config->get('database.connections.'.$connectionName);
        $connConfig = is_array($rawConfig) ? $rawConfig : [];
        $driver = $this->text($connConfig['driver'] ?? null, 'sqlite');

        if ($driver !== 'pgsql') {
            return [DoctorFinding::ok(
                code: 'core.database.pgbouncer.not_applicable',
                message: 'Current Vaulter database driver is not PostgreSQL; PgBouncer checks skipped.',
                evidence: ['connection' => $connectionName, 'driver' => $driver],
            )];
        }

        $findings = [];
        $options = is_array($connConfig['options'] ?? null) ? $connConfig['options'] : [];
        $pgbouncer = is_array($connConfig['pgbouncer'] ?? null) ? $connConfig['pgbouncer'] : [];
        $pgbouncerEnabled = ($pgbouncer['enabled'] ?? false) === true || $pgbouncer !== [];

        $configuredEmulate = $options[PDO::ATTR_EMULATE_PREPARES] ?? null;
        $runtimeEmulate = $this->runtimeEmulatePrepares();

        if ($configuredEmulate === true || $runtimeEmulate === true) {
            $findings[] = DoctorFinding::error(
                code: 'core.database.pgbouncer.emulate_prepares_enabled',
                message: 'PDO::ATTR_EMULATE_PREPARES is enabled on PostgreSQL, which corrupts PG parameter types and breaks PgBouncer prepared statements.',
                remediation: sprintf('Set PDO::ATTR_EMULATE_PREPARES => false in database.connections.%s.options.', $connectionName),
                evidence: ['connection' => $connectionName, 'emulate_prepares' => true],
            );
        } elseif ($configuredEmulate !== false) {
            $findings[] = DoctorFinding::at(
                status: $pgbouncerEnabled ? $context->strictStatus() : CheckStatus::Warning,
                severity: $pgbouncerEnabled ? $context->strictSeverity() : Severity::Warning,
                code: 'core.database.pgbouncer.emulate_prepares_not_pinned',
                message: 'PDO::ATTR_EMULATE_PREPARES => false is not explicitly pinned on the PostgreSQL connection.',
                remediation: sprintf('Explicitly set PDO::ATTR_EMULATE_PREPARES => false in database.connections.%s.options.', $connectionName),
                evidence: ['connection' => $connectionName, 'emulate_prepares_configured' => false],
            );
        }

        $maxPrepared = $pgbouncer['max_prepared_statements']
            ?? $connConfig['max_prepared_statements']
            ?? null;
        $parsedMax = is_int($maxPrepared)
            ? $maxPrepared
            : (is_string($maxPrepared) && ctype_digit($maxPrepared) ? (int) $maxPrepared : null);

        if ($maxPrepared === null) {
            $findings[] = DoctorFinding::at(
                status: $pgbouncerEnabled ? $context->strictStatus() : CheckStatus::Warning,
                severity: $pgbouncerEnabled ? $context->strictSeverity() : Severity::Warning,
                code: 'core.database.pgbouncer.max_prepared_statements_unconfigured',
                message: 'PgBouncer max_prepared_statements is not configured for Doctor validation.',
                remediation: 'Set max_prepared_statements = 200 in pgbouncer.ini (PgBouncer >= 1.21/1.24) and mirror it in database.connections.'.$connectionName.'.pgbouncer.max_prepared_statements.',
                evidence: ['connection' => $connectionName, 'pgbouncer_enabled' => $pgbouncerEnabled],
            );
        } elseif ($parsedMax === null || $parsedMax <= 0) {
            $findings[] = DoctorFinding::error(
                code: 'core.database.pgbouncer.max_prepared_statements_invalid',
                message: 'PgBouncer max_prepared_statements must be a positive integer.',
                remediation: 'Set max_prepared_statements = 200 in pgbouncer.ini and database connection pgbouncer config.',
                evidence: ['connection' => $connectionName, 'max_prepared_statements' => is_scalar($maxPrepared) ? (string) $maxPrepared : 'invalid'],
            );
        } elseif ($parsedMax < self::RECOMMENDED_MAX_PREPARED_STATEMENTS) {
            $findings[] = DoctorFinding::warning(
                code: 'core.database.pgbouncer.max_prepared_statements_low',
                message: sprintf('PgBouncer max_prepared_statements (%d) is below the recommended minimum of %d.', $parsedMax, self::RECOMMENDED_MAX_PREPARED_STATEMENTS),
                remediation: 'Raise max_prepared_statements to at least 200 in pgbouncer.ini.',
                evidence: ['connection' => $connectionName, 'max_prepared_statements' => $parsedMax],
            );
        }

        return $findings === []
            ? [DoctorFinding::ok(
                code: 'core.database.pgbouncer.ok',
                message: 'PostgreSQL PDO::ATTR_EMULATE_PREPARES => false and PgBouncer max_prepared_statements are properly configured.',
                evidence: ['connection' => $connectionName, 'emulate_prepares' => false, 'max_prepared_statements' => $parsedMax],
            )]
            : $findings;
    }

    private function runtimeEmulatePrepares(): ?bool
    {
        try {
            $pdo = $this->database->connection()->getPdo();
            $value = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);

            return is_bool($value) ? $value : (is_int($value) ? $value !== 0 : null);
        } catch (Throwable) {
            return null;
        }
    }

    private function text(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
