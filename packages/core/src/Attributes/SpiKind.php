<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

/** How the host meets an SPI type: it implements it (Vaulter calls it) or it calls it (Vaulter implements it). */
#[Api]
enum SpiKind: string
{
    /** The host or a module implements the type; Vaulter calls it positionally. */
    case Implement = 'implement';

    /** Vaulter implements the type; the host or a module calls it. */
    case Call = 'call';
}
