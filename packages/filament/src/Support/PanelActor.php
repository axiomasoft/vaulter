<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Auth;
use Vaulter\Access\AccessResolver;

/**
 * Resolves the panel's acting admin for the Vaulter resources. The Filament
 * resources never bypass the core ACL: they gate visibility and row actions
 * through the {@see AccessResolver}, which needs an Authorizable
 * actor. This helper narrows the authenticated panel user to one (or null when no
 * one is signed in, which yields an all-false ability map — a read-only shell).
 *
 * Technical axis — no Vaulter domain knowledge beyond "who is acting".
 */
final class PanelActor
{
    /**
     * The authenticated panel user as an Authorizable, or null when there is no
     * acting user (or the guard returned a non-Authorizable).
     */
    public static function current(): ?Authorizable
    {
        $user = Auth::user();

        return $user instanceof Authorizable ? $user : null;
    }

    /**
     * The id used to stamp actorId on the core Actions (granted_by / trashed_by …).
     * Null when no one is acting, so a console-grade write is still attributable as
     * "no actor" rather than a fabricated id.
     */
    public static function id(): int|string|null
    {
        $user = Auth::user();

        if (!$user instanceof Authorizable) {
            return null;
        }

        /** @var int|string|null $id */
        $id = $user->getAuthIdentifier();

        return $id;
    }
}
