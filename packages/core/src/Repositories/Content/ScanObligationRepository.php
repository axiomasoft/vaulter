<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Content;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanAttempt;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Content\ScanResult;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Drive\Drive;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * SQL owns retries and generation fencing; no scanner IO runs in these transactions.
 *
 * @internal The scan work state machine; hosts reach it through the drive scan commands and jobs.
 */
final readonly class ScanObligationRepository
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private ScanSnapshot $snapshots, private ScanEvidenceRepository $evidence, private TenancyConfig $tenancy, private TenancyBoundary $boundary, private ScanPolicyResolver $policies, private ScanWorkTransitions $transitions) {}

    public function find(int|string $id): ?ScanObligation
    {
        $class = $this->config->model('scan_obligation');

        return $class::query()->whereKey($id)->first();
    }

    public function settle(ScanAttempt $attempt, ScanResult $result): bool
    {
        if ($this->tenancy->enabled()) {
            $this->boundary->assertJobDrive($attempt->driveId, $attempt->tenantId);
        }

        return $this->database->transaction(function () use ($attempt, $result): bool {
            // Freeze the owning tree before any preliminary scope reads. A purge
            // that wins first leaves no revision; settlement then safely no-ops.
            $this->database->lockTreeShared($attempt->driveId);
            $revisionClass = $this->config->model('file_revision');
            $revision = $revisionClass::query()->whereKey($attempt->revisionId)->first();
            if ($revision === null) {
                return false;
            }
            $snapshot = $this->snapshots->load($revision, true);
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($attempt->obligationId)->lockForUpdate()->first();
            $now = $this->clock();
            if ($work === null || !$this->transitions->fence($work, $attempt, $now) || !$this->transitions->snapshotMatches($snapshot, $work)) {
                return false;
            }
            $this->transitions->currentSpec($snapshot['drive'], $work->spec_key);
            if ($result->retryable && $work->attempt >= $this->transitions->maxAttempts()) {
                $result = new ScanResult(ScanVerdict::Failed, 'attempts_exhausted');
            }
            $this->transitions->append($work, $result, $now);
            $retry = $result->retryable && $work->attempt < $this->transitions->maxAttempts();
            $delay = [1 => 30, 2 => 120, 3 => 600, 4 => 1800][$work->attempt] ?? 1800;
            $work->forceFill(['state' => $retry ? 'retry_wait' : 'settled', 'outcome' => $result->verdict->value,
                'reason' => $result->reason, 'retryable' => $result->retryable, 'available_at' => $retry ? $now->modify('+'.$delay.' seconds') : $now,
                'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $now])->save();
            $revision = $snapshot['revision'];
            if ($result->verdict === ScanVerdict::Infected) {
                $revision->forceFill(['quarantined_at' => $revision->quarantined_at ?? $now, 'scan_status' => 'infected'])->save();
                if ($snapshot['blob'] === null) {
                    throw new LogicException('Infection requires an exact blob.');
                }
                $snapshot['blob']->forceFill(['quarantined_at' => $snapshot['blob']->quarantined_at ?? $now])->save();
            } elseif ($this->evidence->infected($revision)) {
                $revision->forceFill(['scan_status' => 'infected'])->save();
            } else {
                $clean = $this->evidence->compatible($revision, $attempt->spec) !== null;
                $revision->forceFill(['scan_status' => $clean ? 'clean' : ($retry ? 'pending' : 'failed')])->save();
            }

            return true;
        });
    }

    /** Recovery only writes a failed snapshot, never invokes clean settle after lease expiry. */
    public function recover(ScanObligation $obligation, DateTimeImmutable $now): bool
    {
        return $this->database->transaction(function () use ($obligation, $now): bool {
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($obligation->getRawOriginal($obligation->getKeyName()))->lockForUpdate()->first();
            if ($work === null || $work->generation !== $obligation->generation || $work->state !== 'leased'
                || $work->lease_expires_at > $now) {
                return false;
            }
            if ($work->attempt < $this->transitions->maxAttempts()) {
                $work->forceFill(['state' => 'retry_wait', 'available_at' => $now, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $now])->save();

                return true;
            }
            $this->transitions->terminal($work, 'attempts_exhausted', $now);

            return true;
        });
    }

    public function supersede(ScanObligation $obligation): bool
    {
        return $this->database->transaction(function () use ($obligation): bool {
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($obligation->getRawOriginal($obligation->getKeyName()))->lockForUpdate()->first();
            if ($work === null || $work->generation !== $obligation->generation || $work->state === 'settled') {
                return false;
            }
            $driveClass = $this->config->model('drive');
            $drive = $driveClass::query()->whereKey($work->drive_id)->firstOrFail();
            $specs = $this->policies->forDrive($drive);
            if (array_any($specs, static fn (ScanPolicySpec $spec): bool => $spec->specKey() === $work->spec_key)) {
                return false;
            }
            $this->transitions->terminal($work, 'superseded_policy', $this->clock());

            return true;
        });
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
