<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Resolves the panel's acting admin for the Vaulter resources. The Filament
 * resources never bypass the core ACL: they gate visibility and row actions
 * through the {@see AccessResolver}, which needs an Authorizable
 * actor. This helper narrows the authenticated panel user to one (or null when no
 * one is signed in, which yields an all-false ability map — a read-only shell).
 *
 * The actor is resolved through the ACTIVE PANEL's own auth guard
 * (F-P1.10-02, `Filament::auth()` → `getCurrentOrDefaultPanel()->auth()`), not the
 * application's global default guard: a host whose panel runs on a non-default
 * guard would otherwise have the AccessResolver evaluate a DIFFERENT identity than
 * the one actually signed into the panel. Outside a resolvable panel context
 * (falls back to the default panel; genuinely panel-less callers get whatever that
 * default guard reports) this still degrades to null cleanly.
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
        $user = Filament::auth()->user();

        return $user instanceof Authorizable ? $user : null;
    }

    /**
     * The id used to stamp actorId on the core Actions (granted_by / trashed_by …).
     * Null when no one is acting, so a console-grade write is still attributable as
     * "no actor" rather than a fabricated id.
     */
    public static function id(): int|string|null
    {
        $user = Filament::auth()->user();

        if (!$user instanceof Authorizable) {
            return null;
        }

        /** @var int|string|null $id */
        $id = $user->getAuthIdentifier();

        return $id;
    }
}
