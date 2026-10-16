<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use DateTimeImmutable;
use Vaulter\Attributes\Internal;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Repositories\Content\ScanObligationRepository;

/**
 * Recovery only writes a failed snapshot, never invokes clean settle after lease expiry.
 *
 * Locks only the work row.
 */
#[Internal]
final readonly class RecoverScanWorkAction
{
    public function __construct(private VaulterDatabase $database, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function execute(ScanObligation $obligation, DateTimeImmutable $now): bool
    {
        return $this->database->transaction(function () use ($obligation, $now): bool {
            $work = $this->work->lockPersisted($obligation);
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
}
