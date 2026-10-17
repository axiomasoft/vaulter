<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Tenancy;

use Vaulter\Attributes\Api;
use Vaulter\Exceptions\VaulterException;

/** Strict tenancy has no valid resolver or current tenant; all environments fail closed. */
#[Api]
final class TenancyMisconfiguredException extends VaulterException {}
