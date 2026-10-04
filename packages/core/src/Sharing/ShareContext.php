<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Support\Identity;

/**
 * The authorization context a resolved bearer share produces (§2.7 / M2c) — the
 * SEPARATE sharing pass. It is used INSTEAD of the identity ACL for a
 * share-authorized request: a share grants EXACTLY its own permissions bitmask on
 * its node-set and NOTHING inherited from any identity. The AccessResolver is never
 * consulted on this path.
 *
 * The node-set is the share's scope:
 *  - scope=item    → only the shared node itself;
 *  - scope=subtree → the shared node and its descendants (bounded to the same
 *    storage tree — it can NEVER escape to siblings, ancestors or other storages).
 *
 * Immutable.
 */
final readonly class ShareContext
{
    public function __construct(
        public NodeShare $share,
        public Node $node,
        public int $permissions,
    ) {}

    /**
     * Whether the share's bitmask grants the ability — purely from this share's
     * permissions, never from an identity grant. The deny-veto of the identity ACL
     * has no part here: a share is an allow-only grant of exactly its bits.
     */
    public function allows(Ability $ability): bool
    {
        return $ability->satisfiedBy($this->permissions);
    }

    /**
     * The reach of this share (item|subtree).
     */
    public function scope(): GrantScope
    {
        return $this->share->scope;
    }

    /**
     * Whether $candidate is within this share's node-set (and therefore reachable
     * through the share). For scope=item only the shared node qualifies; for
     * scope=subtree the shared node plus any descendant in the SAME storage tree
     * qualifies. Anything outside — a sibling, an ancestor, a node in another
     * storage — is rejected, so the subtree scope can never be used to escape.
     */
    public function containsNode(Node $candidate): bool
    {
        if ($this->isTarget($candidate)) {
            return true;
        }

        if ($this->scope() !== GrantScope::Subtree) {
            return false;
        }

        // A subtree share never crosses the storage boundary (item-safety §2.7).
        if (Identity::stringify($candidate->drive_id) !== Identity::stringify($this->node->drive_id)) {
            return false;
        }

        return $this->isDescendantOfTarget($candidate);
    }

    private function isTarget(Node $candidate): bool
    {
        return Identity::stringify($candidate->getKey()) === Identity::stringify($this->node->getKey());
    }

    /**
     * Walk the candidate's ancestors (bounded to its storage) and return true iff
     * the shared node is among them. The walk is bounded by the storage-scoped
     * ancestor relation, so it can never leave the tree.
     */
    private function isDescendantOfTarget(Node $candidate): bool
    {
        $targetKey = Identity::stringify($this->node->getKey());

        foreach ($candidate->ancestors()->get() as $ancestor) {
            if (Identity::stringify($ancestor->drive_id) !== Identity::stringify($this->node->drive_id)) {
                break;
            }

            if (Identity::stringify($ancestor->getKey()) === $targetKey) {
                return true;
            }
        }

        return false;
    }
}
