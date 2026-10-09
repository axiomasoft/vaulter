<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Vaulter\Documents\Actions\Collaboration\PersistCollaborationStateAction;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Data\Collaboration\PersistCollaborationStateInput;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * The server side of the hub's authenticate/store calls. These calls carry no user
 * session: they are authenticated by the hub secret or the room JWT (see
 * ResolveCollaborationCall), so they cannot go through DocumentOperations. This keeps
 * their node lookup and persistence out of the controller, which only parses the
 * request.
 *
 * @internal
 */
final readonly class CollaborationHub
{
    public function __construct(
        private NodeReadRepository $nodes,
        private DocumentsConfig $documentsConfig,
        private PersistCollaborationStateAction $persist,
        private DocumentReadRepository $documents,
        private ContentDecoder $decoder,
    ) {}

    /**
     * The document node a hub call addresses. A call may only act on the room its JWT
     * was minted for, never on another node's.
     */
    public function node(int|string $nodeId, CollaborationCall $call): Node
    {
        $node = $this->nodes->find($nodeId);

        if (!$node instanceof Node) {
            throw NodeNotFoundException::forId($nodeId);
        }

        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        if (!$call->mayAddressRoom($this->documentsConfig->collaborationRoom($node->getKey()))) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }

        return $node;
    }

    /**
     * Whether a room on the node may write: the same rule as an autosave, so content
     * this installation cannot save back is shared read-only. The store re-checks it on
     * the locked row.
     */
    public function writable(Node $node): bool
    {
        $document = $this->documents->forNode($node);

        return $document !== null && $this->decoder->editable($document);
    }

    /** @param array<string, mixed>|null $content the projection, decoded by the document's type */
    public function store(Node $node, string $yjsState, ?array $content, int|string|null $actorId): DocumentStateResult
    {
        return $this->persist->execute(new PersistCollaborationStateInput(
            node: $node,
            yjsState: $yjsState,
            content: $content,
            actorId: $actorId,
        ));
    }
}
