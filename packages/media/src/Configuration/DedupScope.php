<?php

declare(strict_types=1);

namespace Vaulter\Media\Configuration;

/**
 * Where identical bytes share one stored blob. Deduplication never
 * crosses a tenant: a shared install-wide scope would let one tenant learn that
 * another stored a given file. Quota and upload responses never depend on a match.
 *
 * @closed The string values are the vaulter-media.dedup.scope settings.
 */
enum DedupScope: string
{
    /** Within one tenant; a drive without a tenant deduplicates within itself. */
    case Tenant = 'tenant';

    /** Within one drive. */
    case Drive = 'drive';

    /** Every upload is stored on its own. */
    case Off = 'off';
}
