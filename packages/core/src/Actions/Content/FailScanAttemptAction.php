<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use LogicException;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanAttempt;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Repositories\Content\ScanObligationRepository;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * A live trusted attempt may record a deterministic failure without trusting altered content.
 *
 * @internal Locks only the work row.
 */
final readonly class FailScanAttemptAction
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private TenancyConfig $tenancy, private TenancyBoundary $boundary, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function execute(ScanAttempt $attempt, string $reason): bool
    {
        if ($this->tenancy->enabled()) {
            $this->boundary->assertJobDrive($attempt->driveId, $attempt->tenantId);
        }
        if (!in_array($reason, ['content_identity_mismatch', 'access_denied', 'unsupported', 'unknown'], true)) {
            throw new LogicException('Invalid deterministic attempt failure.');
        }

        return $this->database->transaction(function () use ($attempt, $reason): bool {
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($attempt->obligationId)->lockForUpdate()->first();
            $now = $this->work->now();
            if ($work === null || !$this->transitions->fence($work, $attempt, $now)) {
                return false;
            }
            $this->transitions->terminal($work, $reason, $now);

            return true;
        });
    }
}
