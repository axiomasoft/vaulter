<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access;

use Packman\Attributes\Internal;
use Vaulter\Configuration\TenancyOptions;
use Vaulter\Drive\Access\ValueObjects\GrantSet;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Enums\Operations\OperationKind;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Operations\NodeLocator;
use Vaulter\Drive\Profiles\ProfilePolicy;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Support\Identity;

#[Internal]
final readonly class OperationGuard
{
    /** The only system reason allowed to touch a drive being deleted. */
    public const string PURGE_REASON = 'drive-purge';

    public function __construct(
        private AccessResolver $resolver,
        private NodeLocator $locator,
        private ProfilePolicy $profiles,
        private TenancyOptions $tenancy,
        private TenancyBoundary $boundary,
    ) {}

    /**
     * @param Drive|null $drive the node's drive as the caller read it within this same decision; it saves the
     *                          re-read and is used only when it is that drive. Pass it only when nothing (a lock,
     *                          a lifecycle change) can have happened since the read.
     */
    public function authorize(DriveContext $context, Ability $ability, Node $node, OperationKind $operation = OperationKind::Metadata, ?Drive $drive = null): void
    {
        $node = $this->scoped(context: $context, node: $node);
        $drive = $this->assertDriveLifecycle(context: $context, node: $node, operation: $operation, drive: $drive);
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

    /**
     * A drive lifecycle change (archive, unarchive, delete): Manage on the drive's root for a user. The system actor
     * (the owner-deleted cascade, host maintenance) is not held to the profile's feature gates: the grants feature
     * governs grant management, and a drive must still be deletable with its owner in a profile that switches grants
     * off. Tenancy and the deleting state still apply to it.
     */
    public function authorizeLifecycle(DriveContext $context, Node $root): void
    {
        if (!$context->actor->isSystem()) {
            $this->authorize(context: $context, ability: Ability::Manage, node: $root);

            return;
        }
        $this->assertDriveLifecycle(context: $context, node: $this->scoped(context: $context, node: $root), operation: OperationKind::Metadata);
    }

    /** Grant management uses the grants feature, independently of bearer links. */
    public function authorizeGrants(DriveContext $context, Node $node): void
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

    /** Grant and share-link changes are mutations: an archived drive refuses them. */
    public function assertMutable(DriveContext $context, Node $node): void
    {
        $this->assertDriveLifecycle(context: $context, node: $this->scoped(context: $context, node: $node), operation: OperationKind::Mutation);
    }

    public function authorizeCreate(DriveContext $context, Node $parent): void
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
    public function visible(DriveContext $context, iterable $nodes): array
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
    public function visibleWith(DriveContext $context, iterable $nodes, Ability $ability): array
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
    private function decide(DriveContext $context, iterable $nodes): array
    {
        $nodes = $this->locator->findMany(context: $context, nodes: $nodes);
        $this->tenantBoundaryMany($nodes);
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
     * needs view, download and copy: one the actor cannot see or take
     * stops the whole copy, so a hidden child never leaks through its folder.
     * Returns the authorised node keys so the Action can refuse a descendant that
     * appears between this check and its locks; null for a system actor, which
     * bypasses only ACL (tenancy is still checked for every node).
     *
     * @param  iterable<Node>    $subtree the root and its descendants, any order
     * @return list<string>|null
     */
    public function copyableSubtree(DriveContext $context, Node $root, iterable $subtree): ?array
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
        $this->tenantBoundaryMany($nodes);
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

    /** @param Drive|null $drive as for {@see self::authorize()} */
    public function abilities(DriveContext $context, Node $node, ?Drive $drive = null): NodeAbilities
    {
        $node = $this->scoped(context: $context, node: $node);
        $grants = $context->actor->isSystem() ? GrantSet::superAllowed() : $this->grants(context: $context, node: $node);
        $this->requireView(grants: $grants, node: $node);
        $drive = $this->driveOf(node: $node, drive: $drive);
        $actor = $context->actor->authorizable();
        $canCreate = $context->actor->isSystem() || ($actor !== null && $this->resolver->canCreateIn(actor: $actor, drive: $drive));
        $flags = [];
        foreach (Ability::cases() as $ability) {
            $flags[$ability->value] = $grants->allows($ability)
                && ($ability !== Ability::Create || $canCreate)
                && $this->profileAllows(context: $context, node: $node, ability: $ability, operation: OperationKind::Metadata, drive: $drive);
        }

        return new NodeAbilities(flags: $flags);
    }

    /**
     * abilities() for a whole page from one batch ACL evaluation, so listing N nodes costs
     * the same number of queries as listing one. Nodes the actor cannot view, or that are
     * not in the context's drive, are left out rather than refused.
     *
     * @param  iterable<Node|NodeSnapshot|int|string> $nodes models, snapshots or ids
     * @return array<string, NodeAbilities>           node key => abilities, in input order
     */
    public function abilitiesMany(DriveContext $context, iterable $nodes): array
    {
        $nodes = $this->locator->findMany(context: $context, nodes: $nodes);
        if ($nodes === []) {
            return [];
        }
        $this->tenantBoundaryMany($nodes);
        $actor = $context->actor->authorizable();
        if ($actor === null && !$context->actor->isSystem()) {
            throw MissingActorException::required();
        }
        $sets = $actor === null ? [] : $this->resolver->evaluateMany(actor: $actor, nodes: $nodes);
        $drive = $context->drive->newQuery()->whereKey($context->driveId())->first()
            ?? throw NodeNotFoundException::forId('drive');
        $canCreate = $context->actor->isSystem() || ($actor !== null && $this->resolver->canCreateIn(actor: $actor, drive: $drive));
        $abilities = [];
        foreach ($nodes as $node) {
            $grants = $context->actor->isSystem() ? GrantSet::superAllowed() : ($sets[(string) $node->id] ?? GrantSet::superDenied());
            if (!$grants->allows(Ability::View)) {
                continue;
            }
            $flags = [];
            foreach (Ability::cases() as $ability) {
                $flags[$ability->value] = $grants->allows($ability)
                    && ($ability !== Ability::Create || $canCreate)
                    && $this->profiles->allows(drive: $drive, node: $node, ability: $ability, operation: OperationKind::Metadata);
            }
            $abilities[(string) $node->id] = new NodeAbilities(flags: $flags);
        }

        return $abilities;
    }

    /**
     * The drive lifecycle: a drive being deleted does not exist for anyone
     * but the system purge; an archived drive refuses every non-system mutation.
     */
    private function assertDriveLifecycle(DriveContext $context, Node $node, OperationKind $operation, ?Drive $drive = null): Drive
    {
        $drive = $this->driveOf(node: $node, drive: $drive);
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

    /** The caller's drive when it is the node's, else the node's drive read now. */
    private function driveOf(Node $node, ?Drive $drive): Drive
    {
        if ($drive !== null && Identity::stringify($drive->getKey()) === Identity::stringify($node->drive_id)) {
            return $drive;
        }

        return $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);
    }

    private function scoped(DriveContext $context, Node $node): Node
    {
        // Trash operations pass an explicitly located trashed node to the guard.
        $node = $this->locator->find(context: $context, key: $node, includeTrashed: true);
        $this->tenantBoundary($node);

        return $node;
    }

    private function grants(DriveContext $context, Node $node): GrantSet
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
        if ($this->tenancy->enabled) {
            $this->boundary->assertNode($node);
        }
    }

    /**
     * tenantBoundary() for a batch: one read of the nodes and one of their drives, so a
     * page under strict tenancy costs the same number of queries as under none.
     *
     * @param list<Node> $nodes
     */
    private function tenantBoundaryMany(array $nodes): void
    {
        if ($this->tenancy->enabled) {
            $this->boundary->assertNodes($nodes);
        }
    }

    private function profileAllows(DriveContext $context, Node $node, Ability $ability, OperationKind $operation, ?Drive $drive = null): bool
    {
        $drive ??= $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);

        return $this->profiles->allows(drive: $drive, node: $node, ability: $ability, operation: $operation);
    }
}
