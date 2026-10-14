<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use Vaulter\Access\OperationGuard;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Operations\NodeLocator;

/**
 * The current authorization of a replayed node write (D25, architecture#operation-chains «Replay»), with the same
 * guard as the write itself: the receipt's node where it is now (after a move, in its new place), else the
 * recorded scope — the parent it was written under — else, when that is gone too, the drive's root. A node the
 * actor cannot see any more, a revoked grant or a gone drive answers like a new request would.
 *
 * Create (`folders.create`) needs Create in the folder that holds the node now; any other write needs `$ability`
 * on the node itself, or on the scope when the node is gone.
 *
 * @internal
 */
final readonly class NodeReplayAuthorizer implements ReplayAuthorizer
{
    private function __construct(
        private DriveContext $context,
        private NodeLocator $locator,
        private OperationGuard $guard,
        private ?Ability $ability,
        private ?string $requestScope,
    ) {}

    /** A replay of folders.create: Create in the folder that holds the created node now (`$parent` for a refusal). */
    public static function forCreate(DriveContext $context, NodeLocator $locator, OperationGuard $guard, ?string $parent): self
    {
        return new self($context, $locator, $guard, null, $parent);
    }

    /** A replay of a write on `$node` that needs `$ability` on it (rename: Edit). */
    public static function forNode(DriveContext $context, NodeLocator $locator, OperationGuard $guard, Ability $ability, string $node): self
    {
        return new self($context, $locator, $guard, $ability, $node);
    }

    public function scope(mixed $result): ResourceRef
    {
        if ($result instanceof NodeSnapshot) {
            return $result->parentId === null ? $this->drive() : new ResourceRef('node', $result->parentId);
        }

        return $this->requestScope === null ? $this->drive() : new ResourceRef('node', $this->requestScope);
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        $node = $resource?->kind === 'node' ? $this->find($resource->id) : null;
        if ($node !== null) {
            if ($this->ability === null) {
                $parent = $node->parent_id === null ? $node : $this->locator->find($this->context, $node->parent_id);
                $this->guard->authorizeCreate($this->context, $parent);

                return;
            }
            $this->guard->authorize($this->context, $this->ability, $node, OperationKind::Mutation);

            return;
        }

        $container = ($scope?->kind === 'node' ? $this->find($scope->id) : null) ?? $this->root();
        if ($this->ability === null) {
            $this->guard->authorizeCreate($this->context, $container);

            return;
        }
        $this->guard->authorize($this->context, $this->ability, $container, OperationKind::Mutation);
    }

    private function find(string $id): ?Node
    {
        try {
            return $this->locator->find($this->context, $id);
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
