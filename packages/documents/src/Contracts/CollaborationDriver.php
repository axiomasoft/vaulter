<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;

/**
 * The node-attached realtime-collaboration transport (master plan §7 / ADR-0001 /
 * M4b). A Hocuspocus driver joins a document node through an external Node hub
 * and mints a signed room JWT. Document content lives in the document aggregate
 * (v_documents working state, v_document_revisions checkpoints).
 *
 * The authorization decision (may this actor connect, and at what level) is NOT the
 * driver's job — the controller resolves it through the Vaulter-owned AccessResolver
 * and passes the resulting ability in. The driver only ENCODES that decision into the
 * transport's session descriptor (readOnly = the ability is not Edit).
 */
interface CollaborationDriver
{
    public function type(): CollaborationDriverType;

    /**
     * Issue a collaboration session for a document node at the granted ability level.
     * The ability is the HIGHEST the AccessResolver granted the actor on the node
     * (View/Comment → read-only, Edit → writable); the driver encodes it into the
     * returned descriptor's readOnly flag and (for Hocuspocus) the room JWT claim.
     *
     * @param int|string $actorId the connecting host user's id (the JWT `sub`)
     */
    public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSession;
}
