<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;

/**
 * Mints a {@see CollaborationSession} for an actor whose access was already decided.
 * It makes no access decision itself: DocumentOperations::collaborationSession()
 * authorizes View through the OperationGuard and decides whether the session may
 * write, so a token never grants more than the guard allowed.
 */
#[Internal]
final readonly class CollaborationSessionService
{
    public function __construct(
        private CollaborationDriverManager $drivers,
        private MetricsRecorder $metrics,
        private DocumentsConfig $documentsConfig,
    ) {}

    /** @param Ability $ability the highest ability the actor holds; only labels the metric */
    public function issue(Node $node, Ability $ability, int $epoch, int $schemaVersion, bool $writable, int|string $actorId): CollaborationSession
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        $session = $this->drivers->driver()->issueSession(
            node: $node,
            epoch: $epoch,
            schemaVersion: $schemaVersion,
            writable: $writable,
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
