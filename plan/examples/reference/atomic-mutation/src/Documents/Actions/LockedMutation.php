<?php

declare(strict_types=1);

namespace VaulterRef\Documents\Actions;

use VaulterRef\Database\Pg;
use VaulterRef\Documents\Content\ContentHasher;
use VaulterRef\Documents\Contracts\LockedDocumentMutation;
use VaulterRef\Documents\Data\DocumentMutationState;
use VaulterRef\Documents\Exceptions\DocumentMutationException;
use VaulterRef\Drive\DriveContext;

/**
 * Target: packages/documents/src/Actions/Documents/LockedMutation.php (#[Internal]). replace() is the
 * BOUNDARY copy of WriteLockedDocumentContent: DocumentStateWriter::write(Update) + DocumentContentUpdated.
 */
final class LockedMutation implements LockedDocumentMutation
{
    private bool $open = true;
    private bool $replaced = false;

    public function __construct(
        private readonly Pg $db,
        private readonly DriveContext $context,
        private DocumentMutationState $state,
        private readonly int $maxContentBytes,
    ) {}

    public function snapshot(): DocumentMutationState
    {
        $this->assertOpen();

        return $this->state;
    }

    public function connection(): Pg
    {
        $this->assertOpen();

        return $this->db;
    }

    public function replace(array $content, int $expectedStateVersion): DocumentMutationState
    {
        $this->assertOpen();
        if ($this->replaced) {
            throw DocumentMutationException::replacedTwice();
        }
        if ($this->state->writeMode !== 'snapshot') {
            throw DocumentMutationException::commandRequired();
        }
        if ($this->state->pending) {
            throw DocumentMutationException::pending();
        }
        if ($expectedStateVersion !== $this->state->position) {
            throw DocumentMutationException::stale($expectedStateVersion, $this->state->position);
        }
        if (($content['type'] ?? null) !== 'doc' || !is_array($content['content'] ?? null)) {
            throw DocumentMutationException::invalidContent('Tiptap root must be {"type":"doc","content":[...]}');
        }
        $encoded = ContentHasher::encode($content);
        if (strlen($encoded) > $this->maxContentBytes) {
            throw DocumentMutationException::invalidContent('content exceeds the configured size limit');
        }
        $this->replaced = true;
        $hash = hash('sha256', $encoded);
        if ($hash === $this->state->contentHash) {
            return $this->state; // an accepted intent with unchanged content keeps the existing position
        }
        $next = $this->state->position + 1;
        if ($next > DocumentMutationState::MAX_WIRE_COUNTER) {
            throw DocumentMutationException::counterOverflow();
        }
        // The predicate repeats what the lock already guarantees; 0 rows would mean the lock order was broken.
        $updated = $this->db->execute(<<<'SQL'
            UPDATE v_documents SET state_version = :next, updated_by = :actor, updated_at = LOCALTIMESTAMP(0)
            WHERE id = :id AND state_version = :position AND collab_epoch = :generation AND write_mode = 'snapshot'
            SQL, [
            'next' => $next, 'actor' => $this->context->subjectId, 'id' => $this->state->documentId,
            'position' => $this->state->position, 'generation' => $this->state->generation,
        ]);
        if ($updated !== 1) {
            throw new \LogicException('The locked head changed inside its own transaction.');
        }
        $this->db->execute(
            'UPDATE v_document_payloads SET content = CAST(:content AS jsonb), content_hash = :hash, content_size = :size WHERE document_id = :id',
            ['content' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'hash' => $hash, 'size' => strlen($encoded), 'id' => $this->state->documentId],
        );
        $this->db->execute(
            'UPDATE v_nodes SET content_modified_at = LOCALTIMESTAMP(0), content_modified_by = :actor WHERE id = :node',
            ['actor' => $this->context->subjectId, 'node' => $this->state->nodeId],
        );
        // DocumentContentUpdated through EventRecorder → v_outbox, same transaction.
        $this->db->execute(<<<'SQL'
            INSERT INTO v_outbox (event_id, tenant_id, scope_kind, scope_id, aggregate_type, aggregate_id, sequence, type, external, payload, created_at)
            VALUES (uuidv7()::text, CAST(:tenant AS bigint), 'drive', CAST(:drive AS text), 'document', CAST(:node AS text), CAST(:sequence AS bigint), 'document.content_updated', false,
                    json_build_object('node_id', CAST(:node AS text), 'state_version', CAST(:sequence AS bigint), 'epoch', CAST(:epoch AS bigint),
                                      'correlation_id', CAST(:correlation AS text)), LOCALTIMESTAMP(0))
            SQL, [
            'tenant' => $this->context->tenantId, 'drive' => $this->context->driveId, 'node' => $this->state->nodeId,
            'sequence' => $next, 'epoch' => $this->state->generation, 'correlation' => $this->context->correlationId,
        ]);
        $this->state = $this->state->withWrite($next, $content, $hash);

        return $this->state;
    }

    /** Called by RunAtomicDocumentMutation when the callback returns or throws. */
    public function close(): void
    {
        $this->open = false;
    }

    private function assertOpen(): void
    {
        if (!$this->open) {
            throw DocumentMutationException::scopeClosed();
        }
    }
}
