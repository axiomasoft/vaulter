<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Access\ValueObjects\AccessExplanation;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Data\Snapshots\GrantSnapshot;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\InheritanceRestriction;

interface AccessOperations
{
    /** @return list<GrantSnapshot> */
    public function grants(NodeSnapshot|string|int $node): array;

    public function grant(NodeSnapshot|string|int $node, GrantInput $grant): GrantSnapshot;

    public function revoke(NodeSnapshot|string|int $node, GrantSnapshot|string|int $grant): void;

    public function explain(NodeSnapshot|string|int $node, Ability $ability, ?Authorizable $subject = null): AccessExplanation;

    /**
     * Stop grants from above applying to the node and its subtree (requires Manage).
     */
    public function restrictInheritance(NodeSnapshot|string|int $node, InheritanceRestriction $mode = InheritanceRestriction::Empty): NodeSnapshot;

    /**
     * Let grants from above apply again; the node's direct grants stay (requires Manage).
     */
    public function restoreInheritance(NodeSnapshot|string|int $node): NodeSnapshot;
}
