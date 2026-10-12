<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

/**
 * Marks an open driver that must be rejected in production.
 *
 * @internal PermissionDriverManager classification seam.
 */
interface NonProductionPermissionDriver extends PermissionDriver {}
