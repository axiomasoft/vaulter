<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use LogicException;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanAttempt;
use Vaulter\Content\ScanResult;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Repositories\Content\ScanObligationRepository;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * Records a scanner verdict for a live lease and quarantines infected content in the same transaction.
 *
 * @internal Lock order: tree shared, node, file, revision, blob, then the work row. No scanner IO runs inside.
 */
final readonly class SettleScanWorkAction
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private TenancyConfig $tenancy, private TenancyBoundary $boundary, private ScanSnapshot $snapshots, private ScanEvidenceRepository $evidence, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function execute(ScanAttempt $attempt, ScanResult $result): bool
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
            $now = $this->work->now();
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
}
