<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * A capability a {@see StorageDriver} implements to declare it can ENUMERATE the
 * disk and report object age. It is the seam the orphan
 * disk-walk (the CAS reconciler's sweepOrphans, in vaulter-media) consumes to find
 * bytes that have no blob row.
 *
 * This is deliberately a SEPARATE capability, not extra methods on the public
 * {@see StorageDriver} contract (mirrors {@see GrantAuthoritativeDriver}):
 * adding to that contract would be a hard BC break for every external host driver.
 * A driver opts IN by implementing this; its ABSENCE is the conservative, BC-safe
 * default — the orphan walk then refuses cleanly (the driver is reported as
 * non-listable and skipped, never a fatal error). The standalone Flysystem storage
 * driver (vaulter-media) implements it out of the box.
 */
#[Spi(SpiKind::Implement)]
interface ListableStorageDriver
{
    /**
     * The object's last-modified time as a Unix timestamp (seconds), or null when
     * the object is absent or the disk cannot report it. The orphan disk-walk uses
     * this to apply the orphan grace — a freshly promoted object whose row is not
     * yet durable must NOT be mistaken for an orphan (null ⇒ age unknown ⇒ skip).
     */
    public function lastModified(string $key, ?string $disk = null): ?int;

    /**
     * Lazily yield the relative path of every FILE on the disk (recursive) — the
     * disk-walk seam the orphan reconciler consumes. A generator so a large disk is
     * streamed, not materialised. Directories are skipped; only files are yielded.
     *
     * @return iterable<string>
     */
    public function listContents(?string $disk = null): iterable;
}
