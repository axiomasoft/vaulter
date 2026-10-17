<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Content;

use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\ScanAttempt;
use Vaulter\Drive\Content\ScanWorkTransitions;
use Vaulter\Drive\Repositories\Content\ScanObligationRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;

/**
 * A live trusted attempt may record a deterministic failure without trusting altered content.
 *
 * Locks only the work row.
 */
#[Internal]
final readonly class FailScanAttemptAction
{
    public function __construct(private VaulterDatabase $database, private TenancyConfig $tenancy, private TenancyBoundary $boundary, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function execute(ScanAttempt $attempt, string $reason): bool
    {
        if ($this->tenancy->enabled()) {
            $this->boundary->assertJobDrive($attempt->driveId, $attempt->tenantId);
        }
        if (!in_array($reason, ['content_identity_mismatch', 'access_denied', 'unsupported', 'unknown'], true)) {
            throw new LogicException('Invalid deterministic attempt failure.');
        }

        return $this->database->transaction(function () use ($attempt, $reason): bool {
            $work = $this->work->lock($attempt->obligationId);
            $now = $this->work->now();
            if ($work === null || !$this->transitions->fence($work, $attempt, $now)) {
                return false;
            }
            $this->transitions->terminal($work, $reason, $now);

            return true;
        });
    }
}
