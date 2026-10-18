<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Access\ValueObjects\AccessExplanation;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\InheritanceRestriction;
use Vaulter\Exceptions\Concurrency\StaleWriteException;

#[Api]
interface AccessOperations
{
    /** @return list<GrantSnapshot> */
    public function grants(NodeSnapshot|string|int $node): array;

    /**
     * Give a subject a role on the node (requires Manage). An append: it expects no version, and it raises the
     * node's access version ({@see NodeSnapshot::$accessVersion}) but never its sequence.
     */
    public function grant(NodeSnapshot|string|int $node, GrantInput $grant): GrantSnapshot;

    /**
     * Revoke one grant of the node (requires Manage). `$expectedAccessVersion` — the node's access version the caller
     * read — is compared atomically with the write; a mismatch throws {@see StaleWriteException}. Null skips the check.
     */
    public function revoke(NodeSnapshot|string|int $node, GrantSnapshot|string|int $grant, ?int $expectedAccessVersion = null): void;

    public function explain(NodeSnapshot|string|int $node, Ability $ability, ?Authorizable $subject = null): AccessExplanation;

    /**
     * Stop grants from above applying to the node and its subtree (requires Manage). `$expectedAccessVersion` as for
     * {@see revoke()}.
     */
    public function restrictInheritance(NodeSnapshot|string|int $node, InheritanceRestriction $mode = InheritanceRestriction::Empty, ?int $expectedAccessVersion = null): NodeSnapshot;

    /**
     * Let grants from above apply again; the node's direct grants stay (requires Manage). `$expectedAccessVersion`
     * as for {@see revoke()}.
     */
    public function restoreInheritance(NodeSnapshot|string|int $node, ?int $expectedAccessVersion = null): NodeSnapshot;
}
