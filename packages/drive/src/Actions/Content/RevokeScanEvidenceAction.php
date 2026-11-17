<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Content;

use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\ScanSnapshot;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Content\ScanAuthorityRepository;
use Vaulter\Drive\Repositories\Content\ScanEvidenceRepository;

/**
 * Revokes scan evidence so it stops qualifying as clean proof; infection stays sticky.
 *
 * Trusted scoped maintenance only. Lock order: tree shared, node, file, revision, blob, then the evidence row.
 */
#[Internal]
final readonly class RevokeScanEvidenceAction
{
    public function __construct(private ScanAuthorityRepository $authority, private VaulterDatabase $database, private ScanSnapshot $snapshots, private ScanEvidenceRepository $evidence) {}

    public function execute(int|string $evidenceId, string $reason, DriveContext $context): void
    {
        if (!$context->actor->isSystem() || trim($reason) === '' || strlen($reason) > 191) {
            throw new LogicException('Evidence revocation requires trusted scoped maintenance and an audit reason.');
        }
        $this->database->transaction(function () use ($evidenceId, $reason, $context): void {
            $record = $this->evidence->findOrFail($evidenceId);
            $snapshot = $this->snapshots->load($this->authority->revisionOrFail($record->revision_id), true);
            if ((string) $snapshot['drive']->id !== (string) $context->driveId()) {
                throw new LogicException('Evidence revocation scope differs.');
            }
            $record = $this->evidence->lockOrFail($evidenceId);
            $record->forceFill(['revoked_at' => now('UTC'), 'revocation_reason' => $reason])->save();
        });
    }
}
