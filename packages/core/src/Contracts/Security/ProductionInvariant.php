<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Security;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * One narrow production-fatal condition of an active module. A module contributes its invariants to the
 * ProductionInvariant slot under their ids (ModuleBuilder::slot()); the kernel evaluates them for HTTP and
 * long-lived workers, never for build commands. Structural configuration errors are not invariants.
 */
#[Spi(SpiKind::Implement)]
interface ProductionInvariant
{
    /** Stable `<module>.<name>` id such as `drive.permission-driver`; it is the slot key. */
    public function id(): string;

    /** Null when satisfied; otherwise one line without secrets that names the config key to fix. */
    public function violation(): ?string;
}
