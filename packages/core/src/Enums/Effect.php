<?php

declare(strict_types=1);

namespace Vaulter\Enums;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The polarity of a node grant (master plan §2.6 / H.2). An allow widens access,
 * a deny narrows it — and a deny ALWAYS wins as the final veto after the max/OR
 * pass, so a host role can never resurrect a level a deny has forbidden.
 *
 * The stored column value is allow|deny (§2.6).
 */
enum Effect: string
{
    use HasLabel;

    #[Label('Allow')]
    case Allow = 'allow';

    #[Label('Deny')]
    case Deny = 'deny';
}
