<?php

declare(strict_types=1);

namespace Vaulter\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Vaulter\Support\Config;

/**
 * Authorizes the webhook-endpoint MANAGEMENT surface (master plan §2.11 / D14 / M3b)
 * — a GLOBAL admin/owner resource, NOT node-scoped (so it never goes through the
 * node AccessResolver). Every management action (register/update/deactivate/delete/
 * list) requires the host's configured manage ability.
 *
 * When config('vaulter.webhooks.manage_ability') is set, the decision DELEGATES to the
 * host Laravel Gate for that ability — the host owns "who is an admin". When it is
 * null (no ability defined), any AUTHENTICATED user is allowed (the route still
 * requires auth); a host MUST define the ability in production. There is deliberately
 * no per-model argument: an endpoint is install-global, not owned by a node/storage.
 */
final class WebhookEndpointPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->canManage($user);
    }

    public function manage(Authenticatable $user): bool
    {
        return $this->canManage($user);
    }

    private function canManage(Authenticatable $user): bool
    {
        $ability = Config::webhookManageAbility();

        if ($ability === null) {
            // No host ability defined: an authenticated user is allowed (the route
            // already required auth). A host tightens this by defining the ability.
            return true;
        }

        return Gate::forUser($user)->allows($ability);
    }
}
