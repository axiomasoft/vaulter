<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Access\ExactListing;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Access\ValueObjects\AccessExplanation;
use Vaulter\Drive\Actions\Access\GrantNodeAccessAction;
use Vaulter\Drive\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Drive\Actions\Access\SetNodeInheritanceAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Access\AccessOperations as Contract;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Access\GrantNodeAccessInput;
use Vaulter\Drive\Data\Access\RevokeGrantInput;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\InheritanceRestriction;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Idempotency\AccessReceiptCodec;
use Vaulter\Drive\Idempotency\AccessReplayAuthorizer;
use Vaulter\Drive\Idempotency\NodeSnapshotCodec;
use Vaulter\Drive\Idempotency\RevokeReceipt;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Node;
use Vaulter\Idempotency\IdempotentWrite;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;

#[Api]
final readonly class AccessOperations implements Contract
{
    #[Internal]
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private AccessResolver $resolver, private DriveConfig $config, private GrantNodeAccessAction $create, private RevokeNodeAccessAction $delete, private SetNodeInheritanceAction $inheritance, private AccessSnapshotMapper $grantMapper, private NodeSnapshotMapper $nodeMapper, private OperationRunner $runner, private ExactListing $cursors) {}

    /** @return list<GrantSnapshot> */
    public function grants(NodeSnapshot|string|int $node): array
    {
        $node = $this->located($node);
        $this->guard->authorizeGrants(context: $this->context, node: $node);
        $class = $this->config->model('grant');

        return array_values($class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (NodeGrant $grant): GrantSnapshot => $this->grantMapper->grant($grant))->all());
    }

    public function grant(NodeSnapshot|string|int $node, GrantInput $grant): GrantSnapshot
    {
        $nodeId = self::idOf($node);
        // An append (D47(4)): no expected version; the receipt keeps the grant as created (D25).
        $idempotency = new IdempotentWrite(
            codec: new AccessReceiptCodec,
            authorizer: AccessReplayAuthorizer::forGrants($this->context, $this->locator, $this->guard, $nodeId),
            fingerprint: ['node' => $nodeId, 'subject_type' => $grant->subject->type, 'subject_id' => (string) $grant->subject->id, 'role' => $grant->role, 'scope' => $grant->scope->value, 'expires_at' => $grant->expiresAt?->format(DateTimeInterface::RFC3339_EXTENDED)],
        );

        return $this->write('grant', function () use ($node, $grant): GrantSnapshot {
            $node = $this->located($node);
            $this->guard->authorizeGrants(context: $this->context, node: $node);
            $this->guard->assertMutable($this->context, $node);

            return $this->grantMapper->grant($this->create->execute(context: $this->context, input: new GrantNodeAccessInput(node: $node, grant: $grant)));
        }, $idempotency);
    }

    /**
     * @param int|null $expectedAccessVersion the node's access version the caller read ({@see NodeSnapshot::$accessVersion});
     *                                        compared under the node's row lock, a mismatch is stale_write. Null skips the check.
     */
    public function revoke(NodeSnapshot|string|int $node, GrantSnapshot|string|int $grant, ?int $expectedAccessVersion = null): void
    {
        $nodeId = self::idOf($node);
        $grantId = $grant instanceof GrantSnapshot ? $grant->id : (string) $grant;
        // The receipt keeps the revoked grant's id and the node the right was proven on (ID2): a replay checks Manage there.
        $idempotency = new IdempotentWrite(
            codec: new AccessReceiptCodec,
            authorizer: AccessReplayAuthorizer::forGrants($this->context, $this->locator, $this->guard, $nodeId),
            fingerprint: ['node' => $nodeId, 'grant' => $grantId, 'expected_access_version' => $expectedAccessVersion],
        );
        $this->write('revoke', function () use ($node, $grantId, $expectedAccessVersion): RevokeReceipt {
            $node = $this->located($node);
            $this->guard->authorizeGrants(context: $this->context, node: $node);
            $this->guard->assertMutable($this->context, $node);
            $class = $this->config->model('grant');
            $row = $class::query()->where('node_id', $node->id)->whereKey($grantId)->first() ?? throw NodeNotFoundException::forId($node->id);
            $this->delete->execute(context: $this->context, input: new RevokeGrantInput(node: $node, grant: $row, expectedAccessVersion: $expectedAccessVersion));

            return new RevokeReceipt(kind: RevokeReceipt::GRANT, id: $grantId, driveId: (string) $this->context->driveId(), nodeId: (string) $node->id, ability: Ability::Manage->value);
        }, $idempotency);
    }

    public function explain(NodeSnapshot|string|int $node, Ability $ability, ?Authorizable $subject = null): AccessExplanation
    {
        $node = $this->located($node);
        $this->guard->authorize(context: $this->context, ability: Ability::Manage, node: $node);
        $subject ??= $this->context->actor->authorizable();
        if ($subject === null) {
            return new AccessExplanation(allowed: true, ability: $ability, reason: AccessExplanation::REASON_SYSTEM, sources: [], restrictions: [], boundaryNodeId: null);
        }

        return $this->resolver->explain(actor: $subject, ability: $ability, node: $node);
    }

    public function restrictInheritance(NodeSnapshot|string|int $node, InheritanceRestriction $mode = InheritanceRestriction::Empty, ?int $expectedAccessVersion = null): NodeSnapshot
    {
        $nodeId = self::idOf($node);
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: AccessReplayAuthorizer::forInheritance($this->context, $this->locator, $this->guard, $nodeId),
            fingerprint: ['node' => $nodeId, 'inherit' => false, 'mode' => $mode->value, 'expected_access_version' => $expectedAccessVersion],
        );

        return $this->write('restrict_inheritance', function () use ($node, $mode, $expectedAccessVersion): NodeSnapshot {
            $node = $this->located($node);
            $this->guard->authorize(context: $this->context, ability: Ability::Manage, node: $node, operation: OperationKind::Mutation);

            return $this->nodeMapper->map($this->inheritance->execute(context: $this->context, node: $node, inherit: false, mode: $mode, expectedAccessVersion: $expectedAccessVersion), $this->context->actor->ref());
        }, $idempotency);
    }

    public function restoreInheritance(NodeSnapshot|string|int $node, ?int $expectedAccessVersion = null): NodeSnapshot
    {
        $nodeId = self::idOf($node);
        $idempotency = new IdempotentWrite(
            codec: new NodeSnapshotCodec,
            authorizer: AccessReplayAuthorizer::forInheritance($this->context, $this->locator, $this->guard, $nodeId),
            fingerprint: ['node' => $nodeId, 'inherit' => true, 'expected_access_version' => $expectedAccessVersion],
        );

        return $this->write('restore_inheritance', function () use ($node, $expectedAccessVersion): NodeSnapshot {
            $node = $this->located($node);
            $this->guard->authorize(context: $this->context, ability: Ability::Manage, node: $node, operation: OperationKind::Mutation);

            return $this->nodeMapper->map($this->inheritance->execute(context: $this->context, node: $node, inherit: true, expectedAccessVersion: $expectedAccessVersion), $this->context->actor->ref());
        }, $idempotency);
    }

    /**
     * @internal HTTP list pagination: one bounded page of the node's direct grants, newest first. The cursor is the
     *           sealed continuation of an earlier page of this same node and actor; the public {@see self::grants()}
     *           is unchanged.
     *
     * @return Page<GrantSnapshot>
     */
    #[Internal]
    public function paginate(NodeSnapshot|string|int $node, int $perPage = 50, ?string $cursor = null): Page
    {
        $node = $this->located($node);
        $this->guard->authorizeGrants(context: $this->context, node: $node);
        $class = $this->config->model('grant');
        $scope = [(string) $this->context->driveId(), $this->context->drive->tenant_id, (string) $node->id, $this->context->actor->ref()];
        $position = $this->cursors->openCursor($cursor, 'grants-list', $scope, ['created_at', 'id']);
        $page = $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(perPage: $perPage, cursor: $position);

        return new Page(
            items: array_values(array_map(fn (NodeGrant $grant): GrantSnapshot => $this->grantMapper->grant($grant), $page->items())),
            nextCursor: $this->cursors->sealCursor($page->nextCursor(), 'grants-list', $scope),
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt, IdempotentWrite $idempotency): mixed
    {
        return $this->runner->write(new OperationSpec('drive.access.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt, $idempotency);
    }

    private static function idOf(NodeSnapshot|string|int $node): string
    {
        return $node instanceof NodeSnapshot ? $node->id : (string) $node;
    }

    private function located(NodeSnapshot|string|int $node): Node
    {
        $node = $this->locator->find(context: $this->context, key: $node);
        if ($node->trashed_at !== null) {
            throw NodeNotFoundException::forId($node->id);
        }

        return $node;
    }
}
