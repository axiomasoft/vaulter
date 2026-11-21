<?php

declare(strict_types=1);

namespace VaulterRef\Documents\Actions;

use Closure;
use VaulterRef\Database\Pg;
use VaulterRef\Documents\Contracts\AtomicDocumentMutation;
use VaulterRef\Documents\Data\DocumentMutationState;
use VaulterRef\Documents\Exceptions\DocumentMutationException;
use VaulterRef\Drive\Ability;
use VaulterRef\Drive\AccessGate;
use VaulterRef\Drive\DriveContext;

/**
 * Target: packages/documents/src/Actions/Documents/RunAtomicDocumentMutation.php, bound to
 * AtomicDocumentMutation in Providers/BindingsRegistrar.php and registered as an operation in
 * DocumentsOperationPolicies.
 *
 * Laravel mapping of lock(): T + N + D = DocumentWriteLock::acquire($context, $node, $nodeId) (shared drive
 * advisory lock, DriveLifecycle::assertWritable, TreeGuard::lock FOR UPDATE, DocumentReadRepository::lockForNode);
 * the Edit decision = OperationGuard::authorize(..., Ability::Edit, $lockedNode, OperationKind::Mutation),
 * executed after acquire() returns and before the callback. The SQL below is the BOUNDARY copy of that sequence.
 */
final readonly class RunAtomicDocumentMutation implements AtomicDocumentMutation
{
    public function __construct(
        private Pg $db,
        private AccessGate $access,
        private int $maxContentBytes = 524288,
    ) {}

    public function run(DriveContext $context, string $nodeId, Closure $operation): mixed
    {
        return $this->db->transaction(function (Pg $db) use ($context, $nodeId, $operation): mixed {
            $locked = new LockedMutation($db, $context, $this->lock($db, $context, $nodeId), $this->maxContentBytes);
            try {
                return $operation($locked);
            } finally {
                $locked->close();
            }
        });
    }

    private function lock(Pg $db, DriveContext $context, string $nodeId): DocumentMutationState
    {
        // T: the drive aggregate, shared — waits only for move/trash/purge holders of the exclusive lock.
        $db->execute('SELECT pg_advisory_xact_lock_shared(hashtext(:key))', ['key' => 'vaulter:drives:'.$context->driveId]);
        $drive = $db->row('SELECT status FROM v_drives WHERE id = :drive AND tenant_id = :tenant',
            ['drive' => $context->driveId, 'tenant' => $context->tenantId]);
        if ($drive === null || $drive['status'] !== 'active') {
            throw DocumentMutationException::unavailable();
        }
        // N: the node row. RevokeNodeAccessAction on this node takes the same row lock first.
        $node = $db->row(
            'SELECT id, drive_id, kind, trashed_at FROM v_nodes WHERE id = :node AND tenant_id = :tenant FOR UPDATE',
            ['node' => $nodeId, 'tenant' => $context->tenantId],
        );
        if ($node === null || $node['drive_id'] !== $context->driveId || $node['trashed_at'] !== null) {
            throw DocumentMutationException::unavailable();
        }
        // Edit on the locked node, a new statement after the lock: a revoke committed before this point is seen.
        if (!$this->access->allows($db, $context, $nodeId, Ability::Edit) || $node['kind'] !== 'document') {
            throw DocumentMutationException::unavailable();
        }
        // D: the head; payload and sync state belong to it and are read in the same statement.
        $row = $db->row(<<<'SQL'
            SELECT d.id, d.type, d.schema_version, d.write_mode, d.collab_epoch, d.state_version,
                   s.collab_protocol, s.pending_protocol, p.content::text AS content, p.content_hash
            FROM v_documents d
            JOIN v_document_payloads p ON p.document_id = d.id
            LEFT JOIN v_document_sync_states s ON s.document_id = d.id
            WHERE d.node_id = :node
            FOR UPDATE OF d
            SQL, ['node' => $nodeId]);
        if ($row === null) {
            throw DocumentMutationException::unavailable();
        }

        return new DocumentMutationState(
            nodeId: $nodeId,
            documentId: $row['id'],
            type: $row['type'],
            schemaVersion: (int) $row['schema_version'],
            generation: (int) $row['collab_epoch'],
            position: (int) $row['state_version'],
            writeMode: $row['write_mode'],
            protocol: $row['collab_protocol'],
            pending: $row['pending_protocol'] !== null,
            content: json_decode($row['content'], true, 512, JSON_THROW_ON_ERROR),
            contentHash: trim((string) $row['content_hash']),
        );
    }
}
