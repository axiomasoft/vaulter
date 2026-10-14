<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Pagination\CursorPaginator;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\OperationGuard;
use Vaulter\Access\ValueObjects\AccessExplanation;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Actions\Access\SetNodeInheritanceAction;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Access\AccessOperations as Contract;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Access\GrantNodeAccessInput;
use Vaulter\Data\Access\RevokeGrantInput;
use Vaulter\Data\Snapshots\GrantSnapshot;
use Vaulter\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\InheritanceRestriction;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;
use Vaulter\Modules\ModuleId;

final readonly class AccessOperations implements Contract
{
    public function __construct(private DriveContext $context, private NodeLocator $locator, private OperationGuard $guard, private AccessResolver $resolver, private VaulterConfig $config, private GrantNodeAccessAction $create, private RevokeNodeAccessAction $delete, private SetNodeInheritanceAction $inheritance, private AccessSnapshotMapper $grantMapper, private NodeSnapshotMapper $nodeMapper, private OperationRunner $runner) {}

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
        return $this->write('grant', function () use ($node, $grant): GrantSnapshot {
            $node = $this->located($node);
            $this->guard->authorizeGrants(context: $this->context, node: $node);
            $this->guard->assertMutable($this->context, $node);

            return $this->grantMapper->grant($this->create->execute(context: $this->context, input: new GrantNodeAccessInput(node: $node, grant: $grant)));
        });
    }

    public function revoke(NodeSnapshot|string|int $node, GrantSnapshot|string|int $grant): void
    {
        $this->write('revoke', function () use ($node, $grant): void {
            $node = $this->located($node);
            $this->guard->authorizeGrants(context: $this->context, node: $node);
            $this->guard->assertMutable($this->context, $node);
            $class = $this->config->model('grant');
            $key = $grant instanceof GrantSnapshot ? $grant->id : $grant;
            $row = $class::query()->where('node_id', $node->id)->whereKey($key)->first() ?? throw NodeNotFoundException::forId($node->id);
            $this->delete->execute(context: $this->context, input: new RevokeGrantInput(node: $node, grant: $row));
        });
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

    public function restrictInheritance(NodeSnapshot|string|int $node, InheritanceRestriction $mode = InheritanceRestriction::Empty): NodeSnapshot
    {
        return $this->write('restrict_inheritance', function () use ($node, $mode): NodeSnapshot {
            $node = $this->located($node);
            $this->guard->authorize(context: $this->context, ability: Ability::Manage, node: $node, operation: OperationKind::Mutation);

            return $this->nodeMapper->map($this->inheritance->execute(context: $this->context, node: $node, inherit: false, mode: $mode), $this->context->actor->ref());
        });
    }

    public function restoreInheritance(NodeSnapshot|string|int $node): NodeSnapshot
    {
        return $this->write('restore_inheritance', function () use ($node): NodeSnapshot {
            $node = $this->located($node);
            $this->guard->authorize(context: $this->context, ability: Ability::Manage, node: $node, operation: OperationKind::Mutation);

            return $this->nodeMapper->map($this->inheritance->execute(context: $this->context, node: $node, inherit: true), $this->context->actor->ref());
        });
    }

    /** @internal Existing HTTP list pagination.
     * @return CursorPaginator<int, GrantSnapshot>
     */
    public function paginate(NodeSnapshot|string|int $node, int $perPage = 50): CursorPaginator
    {
        $node = $this->located($node);
        $this->guard->authorizeGrants(context: $this->context, node: $node);
        $class = $this->config->model('grant');

        return $class::query()->where('node_id', $node->id)->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(perPage: $perPage)->through(fn (NodeGrant $grant): GrantSnapshot => $this->grantMapper->grant($grant));
    }

    /**
     * @template T
     *
     * @param  Closure(): T $attempt
     * @return T
     */
    private function write(string $verb, Closure $attempt): mixed
    {
        return $this->runner->write(new OperationSpec('drive.access.'.$verb, new ModuleId('drive')), $this->context->operation, $attempt);
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
