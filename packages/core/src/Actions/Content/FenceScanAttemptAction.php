<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanAttempt;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Content\ScanWorkTransitions;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Repositories\Content\ScanObligationRepository;

/**
 * Re-checks a leased attempt against the authoritative revision chain before scanner bytes are opened.
 *
 * @internal Read only: no transaction and no lock.
 */
final readonly class FenceScanAttemptAction
{
    public function __construct(private VaulterConfig $config, private ScanSnapshot $snapshots, private ScanObligationRepository $work, private ScanWorkTransitions $transitions) {}

    public function execute(ScanAttempt $attempt): ?FileRevision
    {
        $work = $this->work->find($attempt->obligationId);
        if ($work === null || !$this->transitions->fence($work, $attempt, $this->work->now())) {
            return null;
        }
        $revisionClass = $this->config->model('file_revision');
        $revision = $revisionClass::query()->whereKey($work->revision_id)->first();
        if ($revision === null) {
            return null;
        }
        $snapshot = $this->snapshots->load($revision);
        $this->transitions->currentSpec($snapshot['drive'], $work->spec_key);

        return $this->transitions->snapshotMatches($snapshot, $work) ? $snapshot['revision'] : null;
    }
}
