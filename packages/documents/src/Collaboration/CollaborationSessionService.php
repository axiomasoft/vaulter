<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\AccessResolver;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Data\Collaboration\CollaborationSessionData;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * The read-only evaluator (master plan §7 / M4b) that turns an actor + a document node
 * into a {@see CollaborationSessionData}. It does NOT persist — it resolves the actor's
 * effective ability on the node through the (Vaulter-owned) AccessResolver, maps it to
 * a read/write level, and asks the configured transport driver to mint the session
 * descriptor.
 *
 * The ability gate (H-hardening): the actor must hold at least View/Comment to connect
 * at all (a bare reader joins read-only); Edit grants a writable session. Anything
 * lower 403s before a token is ever minted, so a token NEVER over-grants relative to
 * the actor's ability — readOnly is encoded from the ability, not requested.
 */
final readonly class CollaborationSessionService
{
    public function __construct(
        private AccessResolver $access,
        private NodeCollaborationDriverManager $drivers,
        private MetricsRecorder $metrics,
    ) {}

    /**
     * Issue a collaboration session for the actor on the document node. Requires the
     * node to be kind=document and the actor to hold at least Comment/View; the
     * session is writable only when the actor holds Edit.
     */
    public function issue(Authorizable $actor, Node $node): CollaborationSessionData
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        $ability = $this->grantedAbility($actor, $node);

        $session = $this->drivers->driver()->issueSession(
            node: $node,
            ability: $ability,
            actorId: Identity::key($actor->getAuthIdentifier()) ?? Identity::stringify($actor->getAuthIdentifier()),
        );

        $this->metrics->counter(
            name: 'vaulter_collab_session_issued_total',
            labels: [
                'driver' => Config::collaborationDriverName(),
                'ability' => $ability->value,
            ],
        );

        return $session;
    }

    /**
     * The HIGHEST collaboration ability the actor holds on the node: Edit (writable)
     * when they may edit, else Comment/View (read-only) when they may at least view.
     * No view access at all → a 403 (the actor cannot connect to the room).
     */
    private function grantedAbility(Authorizable $actor, Node $node): Ability
    {
        if ($this->access->can($actor, Ability::Edit, $node)) {
            return Ability::Edit;
        }

        if ($this->access->can($actor, Ability::Comment, $node)) {
            return Ability::Comment;
        }

        if ($this->access->can($actor, Ability::View, $node)) {
            return Ability::View;
        }

        throw PermissionDeniedException::forAbility(Ability::View->value);
    }
}
