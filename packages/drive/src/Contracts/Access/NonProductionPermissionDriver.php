<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Access;

use Vaulter\Attributes\Internal;

/**
 * Marks an open driver that must be rejected in production.
 *
 * PermissionDriverManager classification seam.
 */
#[Internal]
interface NonProductionPermissionDriver extends PermissionDriver {}
