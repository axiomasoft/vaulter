<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Vaulter\Access\ValueObjects\AccessExplanation;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\InheritanceRestriction;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;

interface AccessOperations
{
    /** @return Collection<int, NodeGrant> */
    public function grants(Node|int|string $node): Collection;

    public function grant(Node|int|string $node, GrantInput $grant): NodeGrant;

    public function revoke(Node|int|string $node, NodeGrant|int|string $grant): void;

    public function explain(Node|int|string $node, Ability $ability, ?Authorizable $subject = null): AccessExplanation;

    /**
     * Stop grants from above applying to the node and its subtree (requires Manage).
     */
    public function restrictInheritance(Node|int|string $node, InheritanceRestriction $mode = InheritanceRestriction::Empty): Node;

    /**
     * Let grants from above apply again; the node's direct grants stay (requires Manage).
     */
    public function restoreInheritance(Node|int|string $node): Node;
}
