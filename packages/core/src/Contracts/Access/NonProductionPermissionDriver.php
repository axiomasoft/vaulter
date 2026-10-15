<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * Marks an open driver that must be rejected in production.
 *
 * @internal PermissionDriverManager classification seam.
 */
#[Spi(SpiKind::Implement)]
interface NonProductionPermissionDriver extends PermissionDriver {}
