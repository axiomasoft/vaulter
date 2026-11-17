<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Access;

use Packman\Attributes\Internal;

/**
 * Marks a base subjectsFor implementation that never expands role or team subjects.
 *
 * PermissionDriverManager classification seam.
 */
#[Internal]
interface NonEnumeratingPermissionDriver extends PermissionDriver {}
