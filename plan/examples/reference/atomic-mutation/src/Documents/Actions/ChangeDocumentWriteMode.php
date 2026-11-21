<?php

declare(strict_types=1);

namespace VaulterRef\Documents\Actions;

use VaulterRef\Database\Pg;
use VaulterRef\Documents\Exceptions\DocumentMutationException;
use VaulterRef\Drive\Ability;
use VaulterRef\Drive\AccessGate;
use VaulterRef\Drive\DriveContext;

/**
 * Target: packages/documents/src/Actions/Documents/ChangeDocumentWriteModeAction.php (DB0 / 2.1).
 * snapshot→journal under the same T → N → D order; epoch and state_version rise in one commit.
 * Used by the check to change the mode after a lost response.
 */
final readonly class ChangeDocumentWriteMode
{
    public function __construct(private Pg $db, private AccessGate $access) {}

    /** @return array{generation:int, position:int} */
    public function toJournal(DriveContext $context, string $nodeId, int $expectedGeneration, int $expectedPosition): array
    {
        return $this->db->transaction(function (Pg $db) use ($context, $nodeId, $expectedGeneration, $expectedPosition): array {
            $db->execute('SELECT pg_advisory_xact_lock_shared(hashtext(:key))', ['key' => 'vaulter:drives:'.$context->driveId]);
            $node = $db->row('SELECT id FROM v_nodes WHERE id = :node AND drive_id = :drive AND trashed_at IS NULL FOR UPDATE',
                ['node' => $nodeId, 'drive' => $context->driveId]);
            if ($node === null || !$this->access->allows($db, $context, $nodeId, Ability::Edit)) {
                throw DocumentMutationException::unavailable();
            }
            $row = $db->row(<<<'SQL'
                UPDATE v_documents SET write_mode = 'journal', collab_epoch = collab_epoch + 1, state_version = state_version + 1
                WHERE node_id = :node AND write_mode = 'snapshot' AND collab_epoch = :generation AND state_version = :position
                RETURNING collab_epoch, state_version
                SQL, ['node' => $nodeId, 'generation' => $expectedGeneration, 'position' => $expectedPosition]);
            if ($row === null) {
                throw DocumentMutationException::stale($expectedPosition, -1);
            }

            return ['generation' => (int) $row['collab_epoch'], 'position' => (int) $row['state_version']];
        });
    }
}
