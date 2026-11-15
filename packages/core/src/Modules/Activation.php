<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Vaulter\Attributes\Api;

/**
 * Whether an installed module is active without configuration: `Auto` for the product modules
 * without outside effects, `OptIn` for modules that open the network or need secrets. The host
 * overrides either with `vaulter.modules.<id>`.
 */
#[Api]
enum Activation: string
{
    case Auto = 'auto';
    case OptIn = 'opt-in';
}
