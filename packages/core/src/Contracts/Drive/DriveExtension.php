<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * Marker of the operations a module adds to a drive handle (slot `drive`, a Handler keyed by the FQCN
 * of the operations contract). {@see DriveHandle::extension()} resolves only a declared key; the
 * implementation is built with the handle and the operation context of the caller.
 */
#[Spi(SpiKind::Implement)]
interface DriveExtension {}
