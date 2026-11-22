<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** This document type routes full replacements through its command writer. */
#[Spi(SpiKind::Implement)]
interface RequiresCommandWrite {}
