<?php

declare(strict_types=1);

namespace Vaulter\Access;

use Vaulter\Access\ValueObjects\GrantSet;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Enums\Operations\OperationKind;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Drive\DriveArchivedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Profiles\ProfilePolicy;
use Vaulter\Tenancy\TenancyBoundary;

final readonly class OperationGuard
{
    /** The only system reason allowed to touch a drive being deleted. */
    public const string PURGE_REASON = 'drive-purge';

    public function __construct(
        private AccessResolver $resolver,
        private NodeLocator $locator,
        private ProfilePolicy $profiles,
        private TenancyConfig $tenancy,
        private TenancyBoundary $boundary,
    ) {}

    public function authorize(OperationContext $context, Ability $ability, Node $node, OperationKind $operation = OperationKind::Metadata): void
    {
        $node = $this->scoped(context: $context, node: $node);
        $drive = $this->assertDriveLifecycle(context: $context, node: $node, operation: $operation);
        if (!$context->actor->isSystem()) {
            $grants = $this->grants(context: $context, node: $node);
            $this->requireView(grants: $grants, node: $node);
            if (!$grants->allows($ability)) {
                throw PermissionDeniedException::forAbility($ability->value);
            }
        }
        if (!$this->profileAllows(context: $context, node: $node, ability: $ability, operation: $operation, drive: $drive)) {
            throw ProfileRestrictionException::forOperation($operation->value);
        }
    }

    /** Grant management uses the grants feature, independently of bearer links. */
    public function authorizeGrants(OperationContext $context, Node $node): void
    {
        $node = $this->scoped(context: $context, node: $node);
        $drive = $this->assertDriveLifecycle(context: $context, node: $node, operation: OperationKind::Metadata);
        if (!$context->actor->isSystem()) {
            $grants = $this->grants(context: $context, node: $node);
            $this->requireView(grants: $grants, node: $node);
            if (!$grants->allows(Ability::Manage)) {
                throw PermissionDeniedException::forAbility(Ability::Manage->value);
            }
        }
        if (!$this->profileAllows(context: $context, node: $node, ability: Ability::Manage, operation: OperationKind::Metadata, drive: $drive)) {
            throw ProfileRestrictionException::forOperation('grants');
        }
    }

    /** Grant and share-link changes are mutations: an archived drive refuses them (PLAN2.D37). */
    public function assertMutable(OperationContext $context, Node $node): void
    {
        $this->assertDriveLifecycle(context: $context, node: $this->scoped(context: $context, node: $node), operation: OperationKind::Mutation);
    }

    public function authorizeCreate(OperationContext $context, Node $parent): void
    {
        $parent = $this->scoped(context: $context, node: $parent);
        $drive = $this->assertDriveLifecycle(context: $context, node: $parent, operation: OperationKind::Mutation);
        if (!$context->actor->isSystem()) {
            $grants = $this->grants(context: $context, node: $parent);
            $this->requireView(grants: $grants, node: $parent);
            $drive = $parent->storage()->first() ?? throw NodeNotFoundException::forId($parent->id);
            $actor = $context->actor->authorizable();
            if ($actor === null || !$this->resolver->canCreateIn(actor: $actor, drive: $drive) || !$grants->allows(Ability::Create)) {
                throw PermissionDeniedException::forAbility(Ability::Create->value);
            }
        }
        if (!$this->profileAllows(context: $context, node: $parent, ability: Ability::Create, operation: OperationKind::Mutation, drive: $drive)) {
            throw ProfileRestrictionException::forOperation(OperationKind::Mutation->value);
        }
    }

    /** @param iterable<Node> $nodes
     * @return list<Node>
     */
    public function visible(OperationContext $context, iterable $nodes): array
    {
        return array_map(static fn (array $decision): Node => $decision[0], $this->decide($context, $nodes));
    }

    /**
     * Visible nodes with whether the actor also holds $ability on each, from the same
     * single batch evaluation. A system actor holds every ability.
     *
     * @param  iterable<Node>      $nodes
     * @return array<string, bool> visible node key => holds $ability
     */
    public function visibleWith(OperationContext $context, iterable $nodes, Ability $ability): array
    {
        $holds = [];
        foreach ($this->decide($context, $nodes) as [$node, $set]) {
            $holds[(string) $node->id] = $set === null || $set->allows($ability);
        }

        return $holds;
    }

    /** @param iterable<Node> $nodes
     * @return list<array{Node, ?GrantSet}> visible nodes with their ACL set (null for a system actor)
     */
    private function decide(OperationContext $context, iterable $nodes): array
    {
        $nodes = $this->locator->findMany(context: $context, nodes: $nodes);
        foreach ($nodes as $node) {
            $this->tenantBoundary($node);
        }
        $actor = $context->actor->authorizable();
        $sets = $actor === null ? [] : $this->resolver->evaluateMany(actor: $actor, nodes: $nodes);
        // Refresh the shared drive once for this batch, rather than once per node.
        $drive = $context->drive->newQuery()->whereKey($context->driveId())->first()
            ?? throw NodeNotFoundException::forId('drive');
        $visible = [];
        foreach ($nodes as $node) {
            $set = $context->actor->isSystem() ? null : ($sets[(string) $node->id] ?? GrantSet::superDenied());
            if ($set !== null && !$set->allows(Ability::View)) {
                continue;
            }
            if ($this->profiles->allows(drive: $drive, node: $node, ability: Ability::View, operation: OperationKind::Metadata)) {
                $visible[] = [$node, $set];
            }
        }

        return $visible;
    }

    /**
     * COPY duplicates the content of every active descendant, so each copied node
     * needs view, download and copy (PLAN2.D30/D45): one the actor cannot see or take
     * stops the whole copy, so a hidden child never leaks through its folder.
     * Returns the authorised node keys so the Action can refuse a descendant that
     * appears between this check and its locks; null for a system actor, which
     * bypasses only ACL (tenancy is still checked for every node).
     *
     * @param  iterable<Node>    $subtree the root and its descendants, any order
     * @return list<string>|null
     */
    public function copyableSubtree(OperationContext $context, Node $root, iterable $subtree): ?array
    {
        $active = [];
        foreach ($subtree as $node) {
            $active[(string) $node->id] = $node;
        }
        $included = [(string) $root->id => true];
        do {
            $before = count($included);
            foreach ($active as $id => $node) {
                if ($node->trashed_at === null && isset($included[(string) $node->parent_id])) {
                    $included[$id] = true;
                }
            }
        } while (count($included) > $before);
        $nodes = $this->locator->findMany(context: $context, nodes: array_values(array_intersect_key($active, $included)));
        if (count($nodes) !== count($included)) {
            throw PermissionDeniedException::forAbility(Ability::Copy->value);
        }
        foreach ($nodes as $node) {
            $this->tenantBoundary($node);
        }
        if ($context->actor->isSystem()) {
            return null;
        }
        $actor = $context->actor->authorizable() ?? throw MissingActorException::required();
        $sets = $this->resolver->evaluateMany(actor: $actor, nodes: $nodes);
        foreach ($nodes as $node) {
            $set = $sets[(string) $node->id] ?? GrantSet::superDenied();
            if (!$set->allows(Ability::View) || !$set->allows(Ability::Download) || !$set->allows(Ability::Copy)) {
                throw PermissionDeniedException::forAbility(Ability::Copy->value);
            }
        }

        return array_map(static fn (Node $node): string => (string) $node->id, $nodes);
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

    /**
     * The drive lifecycle (PLAN2.D37): a drive being deleted does not exist for anyone
     * but the system purge; an archived drive refuses every non-system mutation.
     */
    private function assertDriveLifecycle(OperationContext $context, Node $node, OperationKind $operation): Drive
    {
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);
        $purging = $context->actor->isSystem() && $context->actor->ref()->reason === self::PURGE_REASON;
        if ($drive->status === DriveStatus::Deleting && !$purging) {
            throw NodeNotFoundException::forId($node->id);
        }
        if ($drive->status === DriveStatus::Archived && !$context->actor->isSystem()
            && ($operation === OperationKind::Mutation || $operation === OperationKind::Purge)) {
            throw DriveArchivedException::forDrive($drive->id);
        }

        return $drive;
    }

    private function scoped(OperationContext $context, Node $node): Node
    {
        // Trash operations pass an explicitly located trashed node to the guard.
        $node = $this->locator->find(context: $context, key: $node, includeTrashed: true);
        $this->tenantBoundary($node);

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
    private function tenantBoundary(Node $node): void
    {
        if ($this->tenancy->enabled()) {
            $this->boundary->assertNode($node);
        }
    }

    private function profileAllows(OperationContext $context, Node $node, Ability $ability, OperationKind $operation, ?Drive $drive = null): bool
    {
        $drive ??= $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);

        return $this->profiles->allows(drive: $drive, node: $node, ability: $ability, operation: $operation);
    }
}
