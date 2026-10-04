<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Tenancy;

use Vaulter\Exceptions\VaulterException;

/**
 * `vaulter.tenant.enabled` is true but the swappable node model is still the
 * package default, which carries NO tenant scope (F-P1.9-01). Thrown OUTSIDE
 * production to make the otherwise-silent footgun loud; in production the
 * misconfiguration is only logged (the direction of failure is a pre-existing
 * open listing, not a new one — throwing in production would turn a silent
 * cross-tenant leak into a hard outage, which is strictly worse).
 */
final class TenancyMisconfiguredException extends VaulterException {}
