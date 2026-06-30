<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Collaboration;

use Vaulter\Data\CollaborationSession;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Models\Drive\Node;

/**
 * The node-attached realtime-collaboration transport (master plan §7 / ADR-0001 /
 * M4b). It abstracts how a document NODE (kind=document, its content on
 * v_node_document_versions) is joined for realtime editing: the default Reverb driver
 * keeps everything inside Laravel (private broadcast channel); the Hocuspocus driver
 * targets an external Node hub and mints a signed room JWT.
 *
 * This is the NODE-tree seam (M4b) — DISTINCT from the legacy
 * {@see CollaborationDriver}, which is bound to the document_id-attached Document
 * model (the v_documents path that stays intact). The two coexist; a host selects the
 * same transport for both via config('vaulter.collaboration.driver').
 *
 * The authorization decision (may this actor connect, and at what level) is NOT the
 * driver's job — the controller resolves it through the Vaulter-owned AccessResolver
 * and passes the resulting ability in. The driver only ENCODES that decision into the
 * transport's session descriptor (readOnly = the ability is not Edit).
 */
interface NodeCollaborationDriver
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
