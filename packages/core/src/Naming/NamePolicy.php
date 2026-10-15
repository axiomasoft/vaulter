<?php

declare(strict_types=1);

namespace Vaulter\Naming;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Exceptions\Nodes\InvalidNodeNameException;

/**
 * A versioned rule set for new node names, selected per profile through
 * DrivePolicy::$namePolicy. A version never changes once released: tightening the
 * rules ships as a new key. Names already stored are never revalidated; only
 * create, rename, copy-as, upload and a conflict-renaming restore pass it.
 */
#[Spi(SpiKind::Implement)]
interface NamePolicy
{
    /** The stable key a profile selects the policy by, e.g. "portable-v1". */
    public function key(): string;

    /**
     * Normalize the name (NFC, never trimmed) and check it.
     *
     * @throws InvalidNodeNameException with a machine-readable reason
     */
    public function apply(string $name): string;
}
