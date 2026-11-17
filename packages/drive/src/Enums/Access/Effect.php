<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums\Access;

use Packman\Attributes\Api;
use Vaulter\Attributes\Label;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The direction of a host override ({@see PermissionDriver::override()}):
 * Allow is a super-user bypass of the node ACL, Deny is an administrative block
 * that no grant can lift. Grants themselves are always positive.
 */
#[Api]
enum Effect: string
{
    use HasLabel;

    #[Label('Allow')]
    case Allow = 'allow';

    #[Label('Deny')]
    case Deny = 'deny';
}
