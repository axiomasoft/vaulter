<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Packman\Attributes\Api;
use Vaulter\Drive\Enums\Access\Ability;

#[Api]
final readonly class CollaborationAccessDecision
{
    /**
     * @param bool $driveActive whether the drive of the document is active; false (also for any other constructor call) fails closed
     */
    public function __construct(public CollaborationGrant $grant, public Ability $ability, public bool $driveActive = false) {}
}
