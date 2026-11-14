<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;

/**
 * The current authorization of a replayed node write (D25, architecture#operation-chains «Replay»), with the same
 * guard as the write itself: the receipt's node where it is now (after a move, in its new place), else the
 * recorded scope — the parent it was written under — else, when that is gone too, the drive's root. A node the
 * actor cannot see any more, a revoked grant or a gone drive answers like a new request would.
 * Content-bearing document receipts can require the original node to remain available instead of falling back.
 *
 * Create (`folders.create`) needs Create in the folder that holds the node now; any other write needs `$ability`
 * on the node itself, or on the scope when the node is gone. The emptied trash (`trash.empty`) is about the whole
 * drive: its count needs Delete (as a purge) on the drive's root now, before the receipt is decoded.
 */
#[Internal]
final readonly class NodeReplayAuthorizer implements ReplayAuthorizer
{
    private function __construct(
        private DriveContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private ?Ability $ability,
        private ?string $requestScope,
        private OperationKind $kind = OperationKind::Mutation,
        private bool $includeTrashed = false,
        private bool $alsoCreate = false,
        private bool $requireExistingNode = false,
    ) {}

    /** A replay of folders.create: Create in the folder that holds the created node now (`$parent` for a refusal). */
    public static function forCreate(DriveContext $context, NodeLocator $locator, OperationGuard $guard, ?string $parent): self
    {
        return new self($context, $locator, $guard, null, $parent);
    }

    /**
     * A replay of a write on `$node` that needs `$ability` on it (rename: Edit).
     *
     * @param OperationKind $kind                the kind the write itself authorizes as (a purge is not gated like a content mutation)
     * @param bool          $includeTrashed      the node may be in the trash by now (a trashed node, a restore)
     * @param bool          $alsoCreate          the node must also be creatable where it is now (a move: Move on it and Create in its new folder)
     * @param bool          $requireExistingNode a content-bearing receipt must not be disclosed through an ancestor after its node is gone
     */
    public static function forNode(DriveContext $context, NodeLocator $locator, OperationGuard $guard, Ability $ability, string $node, OperationKind $kind = OperationKind::Mutation, bool $includeTrashed = false, bool $alsoCreate = false, bool $requireExistingNode = false): self
    {
        return new self($context, $locator, $guard, $ability, $node, $kind, $includeTrashed, $alsoCreate, $requireExistingNode);
    }

    /**
     * A replay of a write that is about the drive's trash as a whole (trash.empty): the receipt has no node, so it
     * needs Delete as a purge on the drive's root now, the same fallback as a purge whose scope is gone. A fresh empty
     * filters by Delete per trashed root instead; an actor who may delete only in a subtree gets a fresh count, but
     * is refused the stored one, since the aggregate count is not split by root.
     */
    public static function forDrive(DriveContext $context, NodeLocator $locator, OperationGuard $guard): self
    {
        return new self($context, $locator, $guard, Ability::Delete, null, OperationKind::Purge);
    }

    public function scope(mixed $result): ResourceRef
    {
        if ($result instanceof NodeSnapshot) {
            return $result->parentId === null ? $this->drive() : new ResourceRef('node', $result->parentId);
        }
        if ($result instanceof PurgeReceipt) {
            return $result->scopeNodeId === null ? $this->drive() : new ResourceRef('node', $result->scopeNodeId);
        }
        if ($result instanceof FileRevisionSnapshot) {
            return new ResourceRef('node', $result->fileNodeId);
        }

        return $this->requestScope === null ? $this->drive() : new ResourceRef('node', $this->requestScope);
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        $nodeId = $resource?->kind === 'node' ? $resource->id : ($this->requireExistingNode ? $this->requestScope : null);
        $node = $nodeId === null ? null : $this->find($nodeId);
        if ($node !== null) {
            if ($this->ability === null) {
                $parent = $node->parent_id === null ? $node : $this->locator->find($this->context, $node->parent_id);
                $this->guard->authorizeCreate($this->context, $parent);

                return;
            }
            $this->guard->authorize($this->context, $this->ability, $node, $this->kind);
            if ($this->alsoCreate) {
                $this->guard->authorizeCreate($this->context, $node->parent_id === null ? $node : $this->locator->find($this->context, $node->parent_id));
            }

            return;
        }

        if ($this->requireExistingNode) {
            throw NodeNotFoundException::forId($nodeId ?? $this->requestScope ?? 'missing');
        }

        $container = ($scope?->kind === 'node' ? $this->find($scope->id) : null) ?? $this->root();
        if ($this->ability === null) {
            $this->guard->authorizeCreate($this->context, $container);

            return;
        }
        $this->guard->authorize($this->context, $this->ability, $container, $this->kind);
    }

    private function find(string $id): ?Node
    {
        try {
            return $this->locator->find($this->context, $id, includeTrashed: $this->includeTrashed);
        } catch (NodeNotFoundException) {
            return null;
        }
    }

    private function root(): Node
    {
        return $this->locator->find($this->context, $this->context->drive->root_node_id ?? throw NodeNotFoundException::forId('root'));
    }

    private function drive(): ResourceRef
    {
        return new ResourceRef('drive', (string) $this->context->driveId());
    }
}
