<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;

/**
 * Materialises a host user (an Authorizable) by its id so the
 * server-to-server collaboration webhook — which carries only a user id, no session —
 * can run the AccessResolver against a real actor. The host owns its users (Vaulter
 * has no users table), so this is a genuine variance seam: the default
 * {@see \Vaulter\Collaboration\HostUserResolver} defers to the configured auth user-provider,
 * and a host (or a test) may bind its own.
 *
 * Returns null when the id resolves to nothing (the webhook then answers allowed=false
 * rather than failing open).
 */
#[Spi(SpiKind::Implement)]
interface HostUserResolver
{
    public function find(int|string $id): ?Authorizable;
}
