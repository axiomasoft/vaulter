<?php

declare(strict_types=1);

namespace Vaulter\Documents\Access;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver as HostUserResolverContract;

/**
 * The default {@see HostUserResolverContract}: materialise a host user by id by
 * deferring to the host's configured auth user-provider (the host owns its users;
 * Vaulter has no users table), so the resolved user is exactly the one the host would
 * authenticate. Used by the M4b collaboration webhook, which carries only a user id.
 *
 * Owned by vaulter-documents (P2 #5 / ADR-0015) — purely collaboration-webhook
 * machinery, bound by that package's own provider; core neither defines nor binds it.
 *
 * Returns null when no user provider is available or the id resolves to nothing (the
 * webhook then answers allowed=false rather than failing open).
 */
final readonly class HostUserResolver implements HostUserResolverContract
{
    public function __construct(
        private AuthFactory $auth,
    ) {}

    public function find(int|string $id): ?Authorizable
    {
        $guard = $this->auth->guard();

        // The base Guard contract does not declare getProvider(); the concrete guards
        // (Session/Token/RequestGuard) do via GuardHelpers, but a custom guard may not.
        if (!method_exists($guard, 'getProvider')) {
            return null;
        }

        $user = $guard->getProvider()->retrieveById($id);

        return $user instanceof Authorizable ? $user : null;
    }
}
