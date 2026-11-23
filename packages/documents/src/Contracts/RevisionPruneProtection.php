<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Protect dependent references during pruning, independently of Documents retention. */
#[Spi(SpiKind::Implement)]
interface RevisionPruneProtection
{
    /** @return list<string> Revision IDs protected in the current document write transaction. */
    public function protectedRevisionIds(string $documentId): array;
}
