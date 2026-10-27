<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Configuration;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\VaulterException;

/** Raised before HTTP serving or a guarded long-lived command when production invariants are violated. */
#[Api]
final class InsecureProductionConfiguration extends VaulterException
{
    public function problemCode(): string
    {
        return 'insecure_production_configuration';
    }
}
