<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Content;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Drive\Drive;

/**
 * SQL owns retries and generation fencing; no scanner IO runs in these transactions.
 *
 * @internal The scan work state machine; hosts reach it through the drive scan commands and jobs.
 */
final readonly class ScanObligationRepository
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private ScanPolicyResolver $policies, private ScanWorkTransitions $transitions) {}

    public function find(int|string $id): ?ScanObligation
    {
        $class = $this->config->model('scan_obligation');

        return $class::query()->whereKey($id)->first();
    }

    /** @internal The database clock is the only time source for leases, fences and backoff. */
    public function now(): DateTimeImmutable
    {
        return $this->clock();
    }

    private function clock(): DateTimeImmutable
    {
        // Scan timestamps are stored as naive UTC, so each driver must answer in naive UTC.
        // PostgreSQL CURRENT_TIMESTAMP is neither: it carries the session offset and stays frozen at
        // transaction start, which would age every lease and backoff by the time spent waiting on locks.
        $row = $this->database->connection()->selectOne(match ($this->database->driver()) {
            'mysql' => 'SELECT UTC_TIMESTAMP() AS scan_now',
            'pgsql' => "SELECT clock_timestamp() AT TIME ZONE 'UTC' AS scan_now",
            default => 'SELECT CURRENT_TIMESTAMP AS scan_now',
        });
        if (!$row instanceof \stdClass || !isset($row->scan_now) || !is_string($row->scan_now)) {
            throw new LogicException('Database clock is unavailable.');
        }

        return new DateTimeImmutable($row->scan_now, new DateTimeZone('UTC'));
    }
}
