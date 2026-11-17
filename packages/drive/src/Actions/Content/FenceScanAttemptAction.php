<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Content;

use Packman\Attributes\Internal;
use Vaulter\Drive\Content\ScanAttempt;
use Vaulter\Drive\Content\ScanSnapshot;
use Vaulter\Drive\Content\ScanWorkTransitions;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Repositories\Content\ScanAuthorityRepository;
use Vaulter\Drive\Repositories\Content\ScanObligationRepository;

/**
 * Re-checks a leased attempt against the authoritative revision chain before scanner bytes are opened.
 *
 * Read only: no transaction and no lock.
 */
#[Internal]
final readonly class FenceScanAttemptAction
{
    public function __construct(private ScanAuthorityRepository $authority, private ScanSnapshot $snapshots, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function execute(ScanAttempt $attempt): ?FileRevision
    {
        $work = $this->work->find($attempt->obligationId);
        if ($work === null || !$this->transitions->fence($work, $attempt, $this->work->now())) {
            return null;
        }
        $revision = $this->authority->revision($work->revision_id);
        if ($revision === null) {
            return null;
        }
        $snapshot = $this->snapshots->load($revision);
        $this->transitions->currentSpec($snapshot['drive'], $work->spec_key);

        return $this->transitions->snapshotMatches($snapshot, $work) ? $snapshot['revision'] : null;
    }
}
