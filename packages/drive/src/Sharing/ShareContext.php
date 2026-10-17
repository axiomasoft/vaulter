<?php

declare(strict_types=1);

namespace Vaulter\Drive\Sharing;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\Roles\Role;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;

/**
 * The authorization context a resolved bearer share produces — the
 * SEPARATE sharing pass. It is used INSTEAD of the identity ACL for a
 * share-authorized request: a share grants EXACTLY the abilities of its role on its
 * node-set and NOTHING inherited from any identity. The AccessResolver is never
 * consulted on this path.
 *
 * The node-set is the share's scope:
 *  - scope=item    → only the shared node itself;
 *  - scope=subtree → the shared node and its descendants down to the next
 *    inheritance boundary (bounded to the same drive tree — it can NEVER escape to
 *    siblings, ancestors or other drives).
 *
 * Immutable.
 */
#[Internal]
final readonly class ShareContext
{
    public function __construct(
        public NodeShare $share,
        public Node $node,
        public Role $role,
    ) {}

    /**
     * Whether the share's role grants the ability — purely from this share's role,
     * never from an identity grant. A previewer sees but cannot take the original.
     */
    public function allows(Ability $ability): bool
    {
        return $this->role->allows($ability);
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

        // A subtree share never crosses the storage boundary (item-safety).
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
     * Walk the candidate's ancestors (bounded to its drive) and return true iff the
     * shared node is reached before an inheritance boundary: a link on an ancestor
     * stops where grants from above stop (the candidate itself or any node between
     * it and the shared node with inherit_grants=false).
     */
    private function isDescendantOfTarget(Node $candidate): bool
    {
        if (!$candidate->inherit_grants) {
            return false;
        }
        $targetKey = Identity::stringify($this->node->getKey());

        foreach ($candidate->ancestors()->get() as $ancestor) {
            if (Identity::stringify($ancestor->drive_id) !== Identity::stringify($this->node->drive_id)) {
                break;
            }

            if (Identity::stringify($ancestor->getKey()) === $targetKey) {
                return true;
            }

            if (!$ancestor->inherit_grants) {
                return false;
            }
        }

        return false;
    }
}
