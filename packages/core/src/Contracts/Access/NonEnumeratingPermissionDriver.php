<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Access;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * Marks a base subjectsFor implementation that never expands role or team subjects.
 *
 * @internal PermissionDriverManager classification seam.
 */
#[Spi(SpiKind::Implement)]
interface NonEnumeratingPermissionDriver extends PermissionDriver {}
