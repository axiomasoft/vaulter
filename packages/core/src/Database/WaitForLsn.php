<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Packman\Attributes\Api;
use Throwable;
use Vaulter\Configuration\DatabaseOptions;

/**
 * PostgreSQL 19+ `WAIT FOR LSN ... WITH (MODE 'standby_replay', TIMEOUT '120ms', NO_THROW)`
 * helper with automatic fallback to the Primary connection on PG < 19, timeout, or active transactions.
 */
#[Api(since: '1.0')]
final readonly class WaitForLsn
{
    public const int MIN_PG_VERSION_NUM = 190000;

    public const int DEFAULT_TIMEOUT_MS = 120;

    public function __construct(
        private DatabaseOptions $databaseOptions,
        private DatabaseManager $db,
    ) {}

    public function currentPrimaryLsn(?string $primaryConnection = null): ?string
    {
        $connection = $this->db->connection($primaryConnection ?? $this->databaseOptions->connection);
        if ($connection->getDriverName() !== 'pgsql') {
            return null;
        }

        try {
            $rows = $connection->select('SELECT pg_current_wal_lsn()::text AS lsn');
        } catch (Throwable) {
            return null;
        }

        $lsn = $this->extractColumn($rows[0] ?? null, 'lsn');

        return is_string($lsn) && $lsn !== '' ? $lsn : null;
    }

    public function supportsWaitForLsn(Connection $connection): bool
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $rows = $connection->select("SELECT current_setting('server_version_num')::int AS version_num");
        } catch (Throwable) {
            return false;
        }

        $version = $this->extractColumn($rows[0] ?? null, 'version_num');

        return is_numeric($version) && (int) $version >= self::MIN_PG_VERSION_NUM;
    }

    public function wait(Connection $standby, string $lsn, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): bool
    {
        $this->assertValidLsn($lsn);
        $this->assertValidTimeout($timeoutMs);

        if (!$this->supportsWaitForLsn($standby) || $standby->transactionLevel() > 0) {
            return false;
        }

        try {
            $rows = $standby->select(sprintf(
                "WAIT FOR LSN '%s' WITH (MODE 'standby_replay', TIMEOUT '%dms', NO_THROW)",
                $lsn,
                $timeoutMs,
            ));
        } catch (Throwable) {
            return false;
        }

        $status = $this->extractColumn($rows[0] ?? null, 'status');

        return is_string($status) && strtolower($status) === 'success';
    }

    /**
     * Wait for $lsn on the standby connection (when PG >= 19 and available); otherwise execute on Primary.
     *
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn $readCallback
     * @return TReturn
     */
    public function readAfterLsn(
        string $lsn,
        Closure $readCallback,
        ?string $standbyConnection = null,
        ?string $primaryConnection = null,
        int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
    ): mixed {
        $primaryName = $primaryConnection ?? $this->databaseOptions->connection;
        $primary = $this->db->connection($primaryName);

        if ($standbyConnection === null || $standbyConnection === $primaryName) {
            return $readCallback($primary);
        }

        $standby = $this->db->connection($standbyConnection);
        if ($this->wait($standby, $lsn, $timeoutMs)) {
            return $readCallback($standby);
        }

        return $readCallback($primary);
    }

    private function assertValidLsn(string $lsn): void
    {
        if (preg_match('/^[0-9A-Fa-f]{1,8}\/[0-9A-Fa-f]{1,8}$/D', $lsn) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid PostgreSQL LSN [%s].', $lsn));
        }
    }

    private function assertValidTimeout(int $timeoutMs): void
    {
        if ($timeoutMs < 1 || $timeoutMs > 60000) {
            throw new InvalidArgumentException(sprintf('WaitForLsn timeout must be between 1 and 60000 ms, got %d.', $timeoutMs));
        }
    }

    private function extractColumn(mixed $row, string $column): mixed
    {
        if (is_array($row)) {
            return $row[$column] ?? null;
        }

        if (is_object($row)) {
            return get_object_vars($row)[$column] ?? null;
        }

        return null;
    }
}
