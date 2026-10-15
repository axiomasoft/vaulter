<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Vaulter\Attributes\Internal;

/**
 * Marks a base subjectsFor implementation that never expands role or team subjects.
 *
 * PermissionDriverManager classification seam.
 */
#[Internal]
interface NonEnumeratingPermissionDriver extends PermissionDriver {}
