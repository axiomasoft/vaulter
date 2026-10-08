<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;

/**
 * Mints a {@see CollaborationSession} for an actor whose access was already decided.
 * It makes no access decision itself: DocumentOperations::collaborationSession()
 * authorizes View through the OperationGuard (scope, tenant, profile) and passes the
 * highest ability the actor holds — Edit gives a writable session, anything lower a
 * read-only one, so a token never grants more than the guard allowed.
 *
 * @internal
 */
final readonly class CollaborationSessionService
{
    public function __construct(
        private CollaborationDriverManager $drivers,
        private MetricsRecorder $metrics,
        private DocumentsConfig $documentsConfig,
    ) {}

    public function issue(Node $node, Ability $ability, int|string $actorId): CollaborationSession
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        $session = $this->drivers->driver()->issueSession(
            node: $node,
            ability: $ability,
            actorId: $actorId,
        );

        $this->metrics->counter(
            name: 'vaulter_collab_session_issued_total',
            labels: [
                'driver' => $this->documentsConfig->collaborationDriver(),
                'ability' => $ability->value,
            ],
        );

        return $session;
    }
}
