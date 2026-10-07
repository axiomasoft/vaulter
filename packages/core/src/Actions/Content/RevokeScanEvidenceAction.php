<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use LogicException;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Content\ScanEvidenceRepository;

/**
 * Revokes scan evidence so it stops qualifying as clean proof; infection stays sticky.
 *
 * @internal Trusted scoped maintenance only. Lock order: tree shared, node, file, revision, blob, then the evidence row.
 */
final readonly class RevokeScanEvidenceAction
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private ScanSnapshot $snapshots, private ScanEvidenceRepository $evidence) {}

    public function execute(int|string $evidenceId, string $reason, OperationContext $context): void
    {
        if (!$context->actor->isSystem() || trim($reason) === '' || strlen($reason) > 191) {
            throw new LogicException('Evidence revocation requires trusted scoped maintenance and an audit reason.');
        }
        $this->database->transaction(function () use ($evidenceId, $reason, $context): void {
            $record = $this->evidence->findOrFail($evidenceId);
            $revisionClass = $this->config->model('file_revision');
            $snapshot = $this->snapshots->load($revisionClass::query()->whereKey($record->revision_id)->firstOrFail(), true);
            if ((string) $snapshot['drive']->id !== (string) $context->driveId()) {
                throw new LogicException('Evidence revocation scope differs.');
            }
            $record = $this->evidence->lockOrFail($evidenceId);
            $record->forceFill(['revoked_at' => now('UTC'), 'revocation_reason' => $reason])->save();
        });
    }
}
