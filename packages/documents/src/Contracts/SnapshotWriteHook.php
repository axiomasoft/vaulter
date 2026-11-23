<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Reconcile dependent state inside the transaction of a same-generation snapshot edit. */
#[Spi(SpiKind::Implement)]
interface SnapshotWriteHook
{
    public function supportsDocumentType(string $type): bool;

    public function replaceSnapshot(
        string $documentId,
        int $generation,
        DocumentContent $previous,
        DocumentContent $next,
    ): void;
}
