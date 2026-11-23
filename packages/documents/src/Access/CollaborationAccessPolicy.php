<?php

declare(strict_types=1);

namespace Vaulter\Documents\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Documents\Configuration\DocumentsOptions;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Collaboration\CollaborationAccessDecision;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Exceptions\CollaborationProtocolContractException;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Queries\DocumentQuery;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Configuration\DriveOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Kernel;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\Actor;

/** Live, profile-aware authority shared by issuance and every user backend call. */
#[Api]
final readonly class CollaborationAccessPolicy
{
    #[Internal]
    public function __construct(
        private NodeLocator $locator,
        private OperationGuard $guard,
        private DocumentTypeRegistry $types,
        private ContentDecoder $decoder,
        private DocumentQuery $query,
        private Kernel $kernel,
        private Container $container,
        private DriveOptions $drives,
        private AccessResolver $resolver,
    ) {}

    public function evaluate(DriveContext $context, NodeSnapshot|string|int $document, CollaborationMode $ceiling = CollaborationMode::Write, ?int $clientSchemaVersion = null): CollaborationAccessDecision
    {
        return $this->evaluateForSession($context, $document, $clientSchemaVersion, $ceiling)[0];
    }

    /** @return array{CollaborationAccessDecision, Node, Document} */
    #[Internal]
    public function evaluateForSession(DriveContext $context, NodeSnapshot|string|int $document, ?int $clientSchemaVersion, CollaborationMode $ceiling = CollaborationMode::Write): array
    {
        $node = $this->document($context, $document, Ability::View, OperationKind::Metadata);
        $ability = $this->ability($context, $node);
        $aggregate = $this->query->document($node, withState: true, withHead: false, withPayload: $ceiling === CollaborationMode::Write && $ability === Ability::Edit);

        return [$this->evaluateState($context, $node, $aggregate, $clientSchemaVersion, $ceiling, $ability), $node, $aggregate];
    }

    #[Internal]
    public function evaluateState(DriveContext $context, Node $node, Document $aggregate, ?int $clientSchemaVersion, CollaborationMode $ceiling = CollaborationMode::Write, ?Ability $ability = null): CollaborationAccessDecision
    {
        $actor = $context->actor->ref()->id;
        if ($context->actor->isSystem() || $actor === null) {
            throw MissingActorException::required();
        }
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
        $hasCheckpoint = $aggregate->syncState?->collab_state_size !== null;
        if (!$driver->supportsState($aggregate->type, $binding->stateFormat, $binding->schemaVersion, $hasCheckpoint, CollaborationMode::Read)) {
            throw new CollaborationUnavailableException('unsupported_binding', $aggregate->type);
        }
        $ability ??= $this->ability($context, $node);
        // The same rule the hub re-checks on every call: only an editor of content this
        // installation can save back, on an active drive, with a client that speaks the
        // type's schema, gets a room that writes; everyone else watches it.
        $driveActive = $node->storage()->value('status') === DriveStatus::Active;
        $writable = $ceiling === CollaborationMode::Write
            && $ability === Ability::Edit
            && $binding->schemaVersion === $type->schemaVersion()
            && $driver->supportsState($aggregate->type, $binding->stateFormat, $binding->schemaVersion, $hasCheckpoint, CollaborationMode::Write)
            && $this->decoder->editable($aggregate)
            && ($clientSchemaVersion === null || $clientSchemaVersion === $type->schemaVersion())
            && $driveActive;

        return new CollaborationAccessDecision(new CollaborationGrant(
            nodeId: Identity::stringify($node->getKey()), driveId: Identity::stringify($node->drive_id),
            tenant: $node->tenant_id === null ? null : Identity::stringify($node->tenant_id), principal: $context->actor->ref(),
            mode: $writable ? CollaborationMode::Write : CollaborationMode::Read, generation: $binding->generation,
            schemaVersion: $binding->schemaVersion, documentType: $aggregate->type, protocol: $binding->protocol,
            stateFormat: $binding->stateFormat, notAfter: Carbon::now()->getTimestamp() + $this->container->make(DocumentsOptions::class)->collaboration->sessionTtl,
        ), $ability, $driveActive);
    }

    private function ability(DriveContext $context, Node $node): Ability
    {
        $flags = $this->guard->abilities($context, $node)->flags;

        return match (true) {
            $flags[Ability::Edit->value] ?? false => Ability::Edit,
            $flags[Ability::Comment->value] ?? false => Ability::Comment,
            default => Ability::View,
        };
    }

    /**
     * The same decision for a reference a hub carries (drive, node, tenant) instead of a drive handle: the node must
     * exist, be live, sit in the named drive and belong to the tenant, and so must its drive — otherwise it is the 404
     * of a missing node. Everything else is {@see self::evaluate()}.
     *
     * @throws NodeNotFoundException the reference does not name a live node of that drive and tenant
     */
    public function evaluateReference(Actor $actor, string $driveId, string $nodeId, ?string $tenant, string $correlationId, CollaborationMode $ceiling = CollaborationMode::Write, ?int $clientSchemaVersion = null): CollaborationAccessDecision
    {
        $context = $this->referenceContext($actor, $driveId, $nodeId, $tenant, $correlationId);

        return $this->evaluate($context, $nodeId, $ceiling, $clientSchemaVersion);
    }

    /**
     * Authenticate a live reference without issuing a grant to an actor whose View was revoked.
     * A denied View returns null; invalid references retain evaluateReference's strict 404.
     */
    public function evaluateAuthenticationReference(Actor $actor, string $driveId, string $nodeId, ?string $tenant, string $correlationId, CollaborationMode $ceiling = CollaborationMode::Write, ?int $clientSchemaVersion = null): ?CollaborationAccessDecision
    {
        $context = $this->referenceContext($actor, $driveId, $nodeId, $tenant, $correlationId);
        $node = $this->scopedNode($context, $nodeId);
        $user = $actor->authorizable() ?? throw MissingActorException::required();
        if ($node->kind->isDocument() && !$this->resolver->can($user, Ability::View, $node)) {
            return null;
        }

        return $this->evaluate($context, $nodeId, $ceiling, $clientSchemaVersion);
    }

    /** Resolve a trusted room reference to an operation context. Callers still authorize the requested operation. */
    public function referenceContext(Actor $actor, string $driveId, string $nodeId, ?string $tenant, string $correlationId): DriveContext
    {
        if ($actor->isSystem() || $actor->ref()->id === null) {
            throw MissingActorException::required();
        }
        $node = $this->nodeById($nodeId);
        if (!$node instanceof Node
            || Identity::stringify($node->getRawOriginal('drive_id')) !== $driveId
            || $node->trashed_at !== null
            || ($node->getRawOriginal('tenant_id') === null ? null : Identity::stringify($node->getRawOriginal('tenant_id'))) !== $tenant) {
            throw NodeNotFoundException::forId($nodeId);
        }
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId($nodeId);
        if (($drive->tenant_id === null ? null : Identity::stringify($drive->tenant_id)) !== $tenant
            || $drive->status === DriveStatus::Deleting) {
            throw NodeNotFoundException::forId($nodeId);
        }

        return DriveContext::for($drive, $actor, $correlationId);
    }

    /**
     * Whether `$user` may at least View the document node `$nodeId`, by the ordinary access resolver: the check of a
     * realtime room channel. No protocol, write or operation restriction; a missing or non-document node is false.
     */
    public function canViewDocumentNode(Authorizable $user, int|string $nodeId): bool
    {
        $node = $this->nodeById($nodeId);

        return $node instanceof Node && $node->kind->isDocument() && $this->resolver->can($user, Ability::View, $node);
    }

    /** A key the id column cannot hold is missing: PostgreSQL rejects the cast instead of returning no row. */
    private function nodeById(int|string $nodeId): ?Node
    {
        $model = $this->drives->model('node');
        if ($nodeId === '' || !Identity::fitsKey(new $model, $nodeId)) {
            return null;
        }

        $node = $model::query()->find($nodeId);

        return $node instanceof Node ? $node : null;
    }

    private function document(DriveContext $context, NodeSnapshot|string|int $key, Ability $ability, OperationKind $operation): Node
    {
        $node = $this->scopedNode($context, $key);
        // Authorize before the kind check, so a node the actor cannot see stays
        // indistinguishable from a missing one.
        $this->guard->authorize($context, $ability, $node, $operation);
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        return $node;
    }

    /** Apply the same current tenant check to the located node before any ACL decision. */
    private function scopedNode(DriveContext $context, NodeSnapshot|string|int $key): Node
    {
        $node = $this->locator->find($context, $key);
        $tenant = $node->tenant_id === null ? null : Identity::stringify($node->tenant_id);
        if ($tenant !== $context->operation->tenant->id) {
            throw NodeNotFoundException::forId(Identity::stringify($node->getKey()));
        }

        return $node;
    }
}
