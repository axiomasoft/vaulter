<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Materialises a host user (an Authorizable) by its id (master plan §7 / M4b) so the
 * server-to-server collaboration webhook — which carries only a user id, no session —
 * can run the AccessResolver against a real actor. The host owns its users (Vaulter
 * has no users table), so this is a genuine variance seam: the default
 * {@see \Vaulter\Documents\Collaboration\HostUserResolver} defers to the configured auth user-provider,
 * and a host (or a test) may bind its own.
 *
 * Returns null when the id resolves to nothing (the webhook then answers allowed=false
 * rather than failing open).
 */
interface HostUserResolver
{
    public function find(int|string $id): ?Authorizable;
}
