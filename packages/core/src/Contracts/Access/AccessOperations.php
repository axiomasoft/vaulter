<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection;
use Vaulter\Access\ValueObjects\AccessExplanation;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;

interface AccessOperations
{
    /** @return Collection<int, NodeGrant> */
    public function grants(Node|int|string $node): Collection;

    public function grant(Node|int|string $node, GrantInput $grant): NodeGrant;

    public function revoke(Node|int|string $node, NodeGrant|int|string $grant): void;

    public function explain(Node|int|string $node, Ability $ability, ?Authorizable $subject = null): AccessExplanation;
}
