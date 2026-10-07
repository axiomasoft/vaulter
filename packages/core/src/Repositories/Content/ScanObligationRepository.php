<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Content;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Content\ScanRequest;

/**
 * Persistence of scan work rows and their request ledger; transitions live in Vaulter\Actions\Content.
 *
 * @internal Hosts reach scan work through the drive scan commands and jobs.
 */
final readonly class ScanObligationRepository
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database) {}

    public function find(int|string $id): ?ScanObligation
    {
        $class = $this->config->model('scan_obligation');

        return $class::query()->whereKey($id)->first();
    }

    public function make(): ScanObligation
    {
        $class = $this->config->model('scan_obligation');

        return new $class;
    }

    /** Takes the work row lock; callers hold every revision-family lock they need before this one. */
    public function lock(int|string $id): ?ScanObligation
    {
        $class = $this->config->model('scan_obligation');

        return $class::query()->whereKey($id)->lockForUpdate()->first();
    }

    /** Re-reads by the persisted key, so attributes changed on the passed model are never trusted. */
    public function lockPersisted(ScanObligation $obligation): ?ScanObligation
    {
        $class = $this->config->model('scan_obligation');

        return $class::query()->whereKey($obligation->getRawOriginal($obligation->getKeyName()))->lockForUpdate()->first();
    }

    public function lockForRevisionSpec(int|string $revisionId, string $specKey): ?ScanObligation
    {
        $class = $this->config->model('scan_obligation');

        return $class::query()->where('revision_id', $revisionId)->where('spec_key', $specKey)->lockForUpdate()->first();
    }

    public function requestFor(ScanObligation $work): ScanRequest
    {
        $requestClass = $this->config->model('scan_request');

        return $requestClass::query()->where('obligation_id', $work->id)->where('generation', $work->generation)->firstOrFail();
    }

    public function hasRequest(ScanObligation $work, string $requestKey): bool
    {
        $requestClass = $this->config->model('scan_request');

        return $requestClass::query()->where('obligation_id', $work->id)->where('request_key', $requestKey)->exists();
    }

    public function recordRequest(ScanObligation $work, string $requestKey, DateTimeImmutable $requestedAt, string $reason, string $correlationId): void
    {
        $requestClass = $this->config->model('scan_request');
        $request = new $requestClass;
        $request->forceFill(['obligation_id' => $work->id, 'request_key' => $requestKey, 'generation' => $work->generation,
            'requested_at' => $requestedAt, 'reason' => $reason, 'correlation_id' => $correlationId])->save();
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
