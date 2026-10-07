<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Tenancy;

use Vaulter\Exceptions\VaulterException;

/** Strict tenancy has no valid resolver or current tenant; all environments fail closed. */
final class TenancyMisconfiguredException extends VaulterException {}
