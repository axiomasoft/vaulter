<?php

declare(strict_types=1);

namespace Vaulter\Media\Contracts\Cas;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * A capability a {@see ContentStore} implements to declare it can ENUMERATE stored
 * objects and report their age — the consumer seam the
 * orphan disk-walk (the CAS reconciler's sweepOrphans, in vaulter-media) walks to
 * find bytes that have no blob row.
 *
 * This is deliberately a SEPARATE capability, not extra methods on the public
 * {@see ContentStore} contract (mirrors {@see GrantAuthoritativeDriver}):
 * adding to that contract would be a hard BC break for every external implementation.
 * A store opts IN by implementing this; its ABSENCE is the conservative, BC-safe
 * default — the orphan walk then refuses cleanly (listing reported unsupported and
 * skipped, never a fatal error). The standalone sha256 content store (vaulter-media)
 * implements it, delegating to the underlying driver only when that driver is itself
 * a {@see ListableStorageDriver} (otherwise it too reports no listing capability).
 */
#[Spi(SpiKind::Implement)]
interface ListableContentStore
{
    /**
     * The object's last-modified time as a Unix timestamp (seconds), or null when
     * absent / unknown. The orphan disk-walk uses this for the orphan grace so a
     * just-promoted object (its row not yet durable) is not mistaken for an orphan.
     */
    public function lastModified(string $key, ?string $disk = null): ?int;

    /**
     * Lazily yield every stored object KEY on the disk (recursive) — the consumer
     * seam the orphan reconciler walks to find bytes that have no blob row. A
     * generator so a large disk is streamed, not loaded whole.
     *
     * @return iterable<string>
     */
    public function listKeys(?string $disk = null): iterable;
}
