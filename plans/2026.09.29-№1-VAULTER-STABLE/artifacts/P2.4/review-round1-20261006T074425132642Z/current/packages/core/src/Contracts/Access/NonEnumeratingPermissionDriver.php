<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

/**
 * Marks a base subjectsFor implementation that never expands role or team subjects.
 *
 * @internal PermissionDriverManager classification seam.
 */
interface NonEnumeratingPermissionDriver extends PermissionDriver {}
