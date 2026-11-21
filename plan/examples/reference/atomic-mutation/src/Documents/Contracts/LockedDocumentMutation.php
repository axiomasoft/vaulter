<?php

declare(strict_types=1);

namespace VaulterRef\Documents\Contracts;

use VaulterRef\Database\Pg;
use VaulterRef\Documents\Data\DocumentMutationState;

/**
 * Target: packages/documents/src/Contracts/LockedDocumentMutation.php (public, #[Api]).
 * Valid only inside AtomicDocumentMutation::run(); every method throws scope_closed afterwards.
 */
interface LockedDocumentMutation
{
    /** The head, payload and sync binding read under the D lock in this attempt. */
    public function snapshot(): DocumentMutationState;

    /**
     * At most once per attempt. Refuses write_mode=journal (document_command_required), a pending fence,
     * and an $expectedStateVersion other than the locked position (stale_state). Unchanged content returns the
     * current state without a new position. In Laravel: Actions/Documents/WriteLockedDocumentContent shared
     * with UpdateDocumentContentAction (DocumentStateWriter::write + DocumentContentUpdated event).
     *
     * @param array<string, mixed> $content Tiptap JSON; in Laravel a decoded DocumentContent
     */
    public function replace(array $content, int $expectedStateVersion): DocumentMutationState;

    /**
     * The connection that holds this transaction. An adapter writes its receipt, evidence and outbox rows here
     * and nowhere else. In Laravel: VaulterDatabase::connection(); an adapter whose migration connection differs
     * must refuse to register (plan/spec/database/ai.md).
     */
    public function connection(): Pg;
}
