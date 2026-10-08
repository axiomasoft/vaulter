<?php

declare(strict_types=1);

namespace Vaulter\Enums\Sharing;

/**
 * Who may open a share link (PLAN2.D47). The token is always required; the audience
 * adds who must hold it.
 *
 *   anyone         the token is enough
 *   authenticated  the visitor is signed in to the host application
 *   tenant         signed in, and the host's current tenant is the drive's tenant
 *
 * @closed The string values are stored and never change.
 */
enum ShareAudience: string
{
    case Anyone = 'anyone';
    case Authenticated = 'authenticated';
    case Tenant = 'tenant';

    /** Whether the visitor has to be signed in. */
    public function requiresIdentity(): bool
    {
        return $this !== self::Anyone;
    }
}
