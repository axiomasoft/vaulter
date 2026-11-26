<?php

declare(strict_types=1);

namespace Vaulter\Documents\Enums\Documents;

use Packman\Attributes\Api;

/** Snapshot versus journal; part of the public document contract and prepared transitions. */
#[Api]
enum DocumentWriteMode: string
{
    case Snapshot = 'snapshot';
    case Journal = 'journal';
}
