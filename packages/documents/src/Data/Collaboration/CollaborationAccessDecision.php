<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Attributes\Api;
use Vaulter\Drive\Enums\Access\Ability;

#[Api]
final readonly class CollaborationAccessDecision
{
    public function __construct(public CollaborationGrant $grant, public Ability $ability) {}
}
