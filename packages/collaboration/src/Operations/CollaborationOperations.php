<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Operations;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\CollaborationSessionService;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Kernel;
use Vaulter\Support\Identity;

/**
 * The realtime operations of one drive's documents: `$drive->extension(CollaborationOperations::class)`.
 *
 * Every method first locates the node inside the handle's drive (a trashed node, a node of another drive or tenant,
 * or one the actor cannot see is "not found") and asks the OperationGuard for the ability it needs, so the profile,
 * tenancy and content-egress rules apply the same way they do for documents.
 */
#[Api]
final readonly class CollaborationOperations implements DriveExtension
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private DocumentTypeRegistry $types,
        private ContentDecoder $decoder,
        private DocumentQuery $query,
        private CollaborationSessionService $sessions,
        private Kernel $kernel,
    ) {}

    /**
     * A token for the collaboration hub, bound to the document's current generation
     * (epoch): read-write when the actor may edit, read-only otherwise. Needs a user:
     * the hub attributes every change to the token subject. Pass the schema version
     * the client's editor speaks; a different one gets a read-only room.
     *
     * @throws CollaborationUnavailableException when the type cannot collaborate
     */
    public function session(NodeSnapshot|string|int $document, ?int $clientSchemaVersion = null): CollaborationSession
    {
        $node = $this->document($document, Ability::View, OperationKind::Metadata);
        $actor = $this->context->actor->ref()->id;
        if ($this->context->actor->isSystem() || $actor === null) {
            throw MissingActorException::required();
        }
        $aggregate = $this->query->document($node);
        $type = $this->types->find($aggregate->type);
        if ($type !== null && !$type instanceof SupportsCollaboration) {
            throw CollaborationUnavailableException::unsupportedType($type->key());
        }
        $binding = $aggregate->collaborationBinding();
        if (!$type instanceof SupportsCollaboration || $binding === null
            || $type->collaborationProtocol() !== $binding->protocol || $type->collaborationStateFormat() !== $binding->stateFormat) {
            throw new CollaborationUnavailableException('unsupported_binding', $aggregate->type);
        }
        $extensions = $this->kernel->extensions();
        if (!in_array($binding->protocol, $extensions->handlerKeys(CollaborationProtocolDriver::class), true)) {
            throw new CollaborationUnavailableException('protocol_unavailable', $aggregate->type);
        }
        $driver = $extensions->handler(CollaborationProtocolDriver::class, $binding->protocol);
        if ($driver->protocol() !== $binding->protocol) {
            throw new CollaborationProtocolContractException;
        }
        $hasCheckpoint = $aggregate->collab_state !== null;
        if (!$driver->supportsState($aggregate->type, $binding->stateFormat, $binding->schemaVersion, $hasCheckpoint, CollaborationMode::Read)) {
            throw new CollaborationUnavailableException('unsupported_binding', $aggregate->type);
        }
        $flags = $this->guard->abilities($this->context, $node)->flags;
        $ability = match (true) {
            $flags[Ability::Edit->value] ?? false => Ability::Edit,
            $flags[Ability::Comment->value] ?? false => Ability::Comment,
            default => Ability::View,
        };
        // The same rule the hub re-checks on every call: only an editor of content this
        // installation can save back, on an active drive, with a client that speaks the
        // type's schema, gets a room that writes; everyone else watches it.
        $writable = $ability === Ability::Edit
            && $binding->schemaVersion === $type->schemaVersion()
            && $driver->supportsState($aggregate->type, $binding->stateFormat, $binding->schemaVersion, $hasCheckpoint, CollaborationMode::Write)
            && $this->decoder->editable($aggregate)
            && ($clientSchemaVersion === null || $clientSchemaVersion === $type->schemaVersion())
            && $node->storage()->value('status') === DriveStatus::Active;

        return $this->sessions->issue(new CollaborationGrant(
            nodeId: Identity::stringify($node->getKey()), driveId: Identity::stringify($node->drive_id),
            tenant: $node->tenant_id === null ? null : Identity::stringify($node->tenant_id), principal: $this->context->actor->ref(),
            mode: $writable ? CollaborationMode::Write : CollaborationMode::Read, generation: $binding->generation,
            schemaVersion: $binding->schemaVersion, documentType: $aggregate->type, protocol: $binding->protocol,
            stateFormat: $binding->stateFormat, notAfter: time() + 300,
        ), $ability);
    }

    private function document(NodeSnapshot|string|int $key, Ability $ability, OperationKind $operation): Node
    {
        $node = $this->locator->find($this->context, $key);
        // Authorize before the kind check, so a node the actor cannot see stays
        // indistinguishable from a missing one.
        $this->guard->authorize($this->context, $ability, $node, $operation);
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        return $node;
    }
}
