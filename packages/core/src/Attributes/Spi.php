<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

use Attribute;

/**
 * A supported type for the code that extends Vaulter: a contract a host or a module implements
 * ({@see SpiKind::Implement}) or a service it calls ({@see SpiKind::Call}). The kernel calls an implemented
 * contract positionally, so its parameter types and order are frozen, not its parameter names.
 */
#[Api]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Spi
{
    public function __construct(
        public SpiKind $kind,
        /** The release that first promised it. */
        public string $since = '1.0',
    ) {}
}
