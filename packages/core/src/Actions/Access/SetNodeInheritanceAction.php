<?php

declare(strict_types=1);

namespace Vaulter\Actions\Access;

use Illuminate\Support\Carbon;
use Vaulter\Access\Roles\Role;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\InheritanceRestriction;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\NodeLocator;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Access\GrantReadRepository;
use Vaulter\Repositories\Access\GrantStoreRepository;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Identity;

/**
 * Opens or closes a node to grants from above (inherit_grants). Restoring keeps the
 * node's direct grants. Restricting with copy_current first gives each subject that
 * currently inherits access a direct subtree grant on the node — never broader or
 * longer-lived than what it inherits: per subject the role covering the inherited
 * roles, until the latest expiry among the grants that carry that role. One row per
 * subject and scope cannot hold two lifetimes, so where a direct subtree grant
 * already exists the conflict resolves towards less access (see keepsDirect()).
 *
 * @internal Authorization (Manage on the node) belongs to AccessOperations.
 */
final readonly class SetNodeInheritanceAction
{
    public function __construct(
        private VaulterDatabase $database,
        private NodeLocator $locator,
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private GrantReadRepository $grants,
        private GrantStoreRepository $writer,
    ) {}

    public function execute(OperationContext $context, Node $node, bool $inherit, InheritanceRestriction $mode = InheritanceRestriction::Empty): Node
    {
        $node = $this->locator->find(context: $context, key: $node);

        return $this->database->transaction(function () use ($context, $node, $inherit, $mode): Node {
            $locked = $this->store->lockForUpdate([$node->id])->first() ?? throw NodeNotFoundException::forId($node->id);
            if ((bool) $locked->inherit_grants === $inherit) {
                return $locked;
            }
            if (!$inherit && $mode === InheritanceRestriction::CopyCurrent) {
                $this->copyInherited(context: $context, node: $locked);
            }

            return $this->store->update($locked, ['inherit_grants' => $inherit]);
        });
    }

    private function copyInherited(OperationContext $context, Node $node): void
    {
        if ($node->parent_id === null) {
            return;
        }
        $chain = $this->nodes->ancestorChains([$node->parent_id])[Identity::stringify($node->parent_id)] ?? [];
        $ids = [];
        foreach ($chain as $row) {
            if (Identity::stringify($row->drive_id) === Identity::stringify($node->drive_id) && (is_int($row->id) || is_string($row->id))) {
                $ids[] = $row->id;
            }
        }
        $now = Carbon::now();
        $bySubject = [];
        foreach ($this->grants->subtreeGrantsOn($ids, $now) as $grant) {
            $bySubject[$grant->subject()->signature()][] = $grant;
        }
        foreach ($bySubject as $inherited) {
            [$role, $expiresAt] = $this->covering($inherited);
            $subject = $inherited[0]->subject();
            $direct = $this->grants->forSubjectsOnNodes([$node->id], [$subject], $now)
                ->first(static fn (NodeGrant $grant): bool => $grant->scope === GrantScope::Subtree);
            if ($direct instanceof NodeGrant && $this->keepsDirect($direct, $role, $expiresAt)) {
                continue;
            }
            $copy = $this->writer->put(node: $node, subject: $subject, scope: GrantScope::Subtree, role: $role, expiresAt: $expiresAt, grantedBy: $context->actor->ref()->id);
            event(new NodeGrantCreated(grant: $copy, actorId: $context->actor->ref()->id, actor: $context->actor->ref(), correlationId: $context->correlationId));
        }
    }

    /**
     * The role covering one subject's inherited grants and how long it lasts.
     *
     * @param  list<NodeGrant>            $grants
     * @return array{0: Role, 1: ?Carbon}
     */
    private function covering(array $grants): array
    {
        $roles = array_map(static fn (NodeGrant $grant): Role => $grant->role(), $grants);
        $role = Role::covering($roles) ?? throw new \LogicException('[Vaulter] Inherited roles do not nest.');
        $expiresAt = null;
        foreach ($grants as $grant) {
            if (!$grant->role()->covers($role)) {
                continue;
            }
            if ($grant->expires_at === null) {
                return [$role, null];
            }
            if ($expiresAt === null || $grant->expires_at->greaterThan($expiresAt)) {
                $expiresAt = $grant->expires_at;
            }
        }

        return [$role, $expiresAt];
    }

    /**
     * A direct grant at least as broad stays (widening its lifetime would extend
     * its extra abilities); a narrower one that strictly outlives the inherited role
     * stays too (replacing it would cut its own lifetime). Otherwise the covering
     * role replaces it, which keeps exactly the union over time.
     */
    private function keepsDirect(NodeGrant $direct, Role $role, ?Carbon $expiresAt): bool
    {
        if ($direct->role()->covers($role)) {
            return true;
        }

        return $expiresAt !== null && ($direct->expires_at === null || $direct->expires_at->greaterThan($expiresAt));
    }
}
