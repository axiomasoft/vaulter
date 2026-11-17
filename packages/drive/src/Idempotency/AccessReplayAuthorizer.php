<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use Packman\Attributes\Internal;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Data\Snapshots\MintedShareLink;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Idempotency\ReplayAuthorizer;
use Vaulter\Idempotency\ResourceRef;

/**
 * The current authorization of a replayed write on the access of a node — its grants, its inheritance, its share
 * links (ID2) — with the same guard as the write itself, on the node the receipt names (a revocation: the node
 * recorded in its receipt, since the grant is gone). A node that is trashed, gone or invisible now, a revoked Manage
 * or Share, an archived drive: the replay is refused exactly as a new request would be, and the stored result —
 * a share token included — is not returned.
 */
#[Internal]
final readonly class AccessReplayAuthorizer implements ReplayAuthorizer
{
    private const string GRANTS = 'grants';

    private const string INHERITANCE = 'inheritance';

    private const string SHARES = 'shares';

    private function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private string $mode, private string $node) {}

    /** drive.access.grant and drive.access.revoke: Manage on the node through the grants gate, on a mutable drive. */
    public static function forGrants(DriveContext $context, NodeLocator $locator, OperationGuard $guard, string $node): self
    {
        return new self($context, $locator, $guard, self::GRANTS, $node);
    }

    /** drive.access.restrict_inheritance and drive.access.restore_inheritance: Manage on the node, as a mutation. */
    public static function forInheritance(DriveContext $context, NodeLocator $locator, OperationGuard $guard, string $node): self
    {
        return new self($context, $locator, $guard, self::INHERITANCE, $node);
    }

    /** drive.shares.*: Share on the node, on a mutable drive. */
    public static function forShares(DriveContext $context, NodeLocator $locator, OperationGuard $guard, string $node): self
    {
        return new self($context, $locator, $guard, self::SHARES, $node);
    }

    public function scope(mixed $result): ResourceRef
    {
        return new ResourceRef('node', match (true) {
            $result instanceof GrantSnapshot => $result->nodeId,
            $result instanceof RevokeReceipt => $result->nodeId,
            $result instanceof ShareLinkSnapshot => $result->nodeId,
            $result instanceof MintedShareLink => $result->link->nodeId,
            $result instanceof NodeSnapshot => $result->id,
            default => $this->node,
        });
    }

    public function authorize(?ResourceRef $resource, ?ResourceRef $scope): void
    {
        $id = $resource?->kind === 'node' ? $resource->id : ($scope?->kind === 'node' ? $scope->id : $this->node);
        $node = $this->locator->find($this->context, $id);
        if ($node->trashed_at !== null) {
            throw NodeNotFoundException::forId($node->id);
        }
        $this->check($node);
    }

    private function check(Node $node): void
    {
        match ($this->mode) {
            self::GRANTS => $this->guard->authorizeGrants(context: $this->context, node: $node),
            self::INHERITANCE => $this->guard->authorize(context: $this->context, ability: Ability::Manage, node: $node, operation: OperationKind::Mutation),
            default => $this->guard->authorize(context: $this->context, ability: Ability::Share, node: $node),
        };
        if ($this->mode !== self::INHERITANCE) {
            $this->guard->assertMutable($this->context, $node);
        }
    }
}
