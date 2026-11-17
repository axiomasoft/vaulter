<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Access;

use Packman\Attributes\Internal;

/**
 * Marks an open driver that must be rejected in production.
 *
 * PermissionDriverManager classification seam.
 */
#[Internal]
interface NonProductionPermissionDriver extends PermissionDriver {}
