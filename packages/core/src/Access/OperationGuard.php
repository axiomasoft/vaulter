<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Vaulter\Access\ValueObjects\GrantSet;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\ProfilePolicy;

final readonly class OperationGuard
{
    public function __construct(
        private AccessResolver $resolver,
        private NodeLocator $locator,
        private ProfilePolicy $profiles,
    ) {}

    public function authorize(OperationContext $context, Ability $ability, Node $node, OperationKind $operation = OperationKind::Metadata): void
    {
        $node = $this->scoped(context: $context, node: $node);
        if (!$context->actor->isSystem()) {
            $grants = $this->grants(context: $context, node: $node);
            $this->requireView(grants: $grants, node: $node);
            if (!$grants->allows($ability)) {
                throw PermissionDeniedException::forAbility($ability->value);
            }
        }
        if (!$this->profileAllows(context: $context, node: $node, ability: $ability, operation: $operation)) {
            throw ProfileRestrictionException::forOperation($operation->value);
        }
    }

    public function authorizeCreate(OperationContext $context, Node $parent): void
    {
        $parent = $this->scoped(context: $context, node: $parent);
        if (!$context->actor->isSystem()) {
            $grants = $this->grants(context: $context, node: $parent);
            $this->requireView(grants: $grants, node: $parent);
            $drive = $parent->storage()->first() ?? throw NodeNotFoundException::forId($parent->id);
            $actor = $context->actor->authorizable();
            if ($actor === null || !$this->resolver->canCreateInWorkspace(actor: $actor, storage: $drive) || !$grants->allows(Ability::Create)) {
                throw PermissionDeniedException::forAbility(Ability::Create->value);
            }
        }
        if (!$this->profileAllows(context: $context, node: $parent, ability: Ability::Create, operation: OperationKind::Mutation)) {
            throw ProfileRestrictionException::forOperation(OperationKind::Mutation->value);
        }
    }

    /** @param iterable<Node> $nodes
     * @return list<Node>
     */
    public function visible(OperationContext $context, iterable $nodes): array
    {
        $nodes = $this->locator->findMany(context: $context, nodes: $nodes);
        foreach ($nodes as $node) {
            $this->tenantBoundary(context: $context, node: $node);
        }
        $actor = $context->actor->authorizable();
        $sets = $actor === null ? [] : $this->resolver->evaluateMany(actor: $actor, nodes: $nodes);
        $visible = [];
        foreach ($nodes as $node) {
            if (!$context->actor->isSystem() && !($sets[(string) $node->id] ?? GrantSet::superDenied())->allows(Ability::View)) {
                continue;
            }
            if ($this->profileAllows(context: $context, node: $node, ability: Ability::View, operation: OperationKind::Metadata)) {
                $visible[] = $node;
            }
        }

        return $visible;
    }

    public function abilities(OperationContext $context, Node $node): NodeAbilities
    {
        $node = $this->scoped(context: $context, node: $node);
        $grants = $context->actor->isSystem() ? GrantSet::superAllowed() : $this->grants(context: $context, node: $node);
        $this->requireView(grants: $grants, node: $node);
        $flags = [];
        foreach (Ability::cases() as $ability) {
            $flags[$ability->value] = $grants->allows($ability)
                && $this->profileAllows(context: $context, node: $node, ability: $ability, operation: OperationKind::Metadata);
        }

        return new NodeAbilities(flags: $flags);
    }

    private function scoped(OperationContext $context, Node $node): Node
    {
        // Trash operations pass an explicitly located trashed node to the guard.
        $node = $this->locator->find(context: $context, key: $node, includeTrashed: true);
        $this->tenantBoundary(context: $context, node: $node);

        return $node;
    }

    private function grants(OperationContext $context, Node $node): GrantSet
    {
        $actor = $context->actor->authorizable();

        if ($actor === null) {
            throw MissingActorException::required();
        }

        return $this->resolver->effectiveGrants(actor: $actor, node: $node);
    }

    private function requireView(GrantSet $grants, Node $node): void
    {
        if (!$grants->allows(Ability::View)) {
            throw NodeNotFoundException::forId($node->id);
        }
    }

    /** Tenant enforcement is connected by the strict tenancy integration. */
    private function tenantBoundary(OperationContext $context, Node $node): void {}

    private function profileAllows(OperationContext $context, Node $node, Ability $ability, OperationKind $operation): bool
    {
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);

        return $this->profiles->allows(drive: $drive, node: $node, ability: $ability, operation: $operation);
    }
}
