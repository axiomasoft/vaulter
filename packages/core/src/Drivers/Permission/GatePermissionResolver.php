<?php

declare(strict_types=1);

namespace Vaulter\Drivers\Permission;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Vaulter\Access\ValueObjects\AclScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Contracts\Access\HostRoleDriver;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Support\GateConfig;

/**
 * The Laravel-Gate host-role driver (master plan §6 / H.2) — a zero-extra-dependency
 * lane, because the Gate ships with the framework. It bridges a host that already
 * authorises through Gate abilities / policies into Vaulter WITHOUT teaching Vaulter
 * the host's abilities: every coupling lives in a configurable ability map.
 *
 *   - subjectsFor(actor) = [Subject(actor)] — the Gate exposes no role/team subjects
 *     (abilities are evaluated against the user, not enumerated), so only the actor's
 *     own identity joins the subject-set; v_node_grants addressed to a role/team are
 *     simply not matched by this lane (use the Spatie/Azgard lane for that).
 *   - override(actor, scope) = a configurable super-admin ability (default
 *     'vaulter.super'); when the Gate allows it the actor short-circuits to full
 *     access, else this lane defers (null) — never a hard deny.
 *   - resolve(subject, actor, scope) = per requested level, the Gate is asked the
 *     mapped ability ('vaulter.view'/'vaulter.edit'/…); the HIGHEST allowed level
 *     becomes the Decision. The Gate check is Node-BLIND: it receives the AclScope's
 *     panel + workspace coordinates, never a Node, so the contract stays swappable.
 *   - canCreateInWorkspace = the configured create ability, passed [type, id].
 *
 * Every Gate check goes through Gate::forUser($actor) so the driver is stateless and
 * never depends on the currently-authenticated user.
 */
final class GatePermissionResolver implements HostRoleDriver
{
    /**
     * Per requested level (low → high) the Gate is asked the mapped ability; the
     * highest one it allows becomes the role Decision the AccessResolver folds in.
     * The AccessResolver itself iterates the subject-set, but the Gate is per-user
     * (not per-subject), so we evaluate the actor's own abilities here and the
     * resolve loop converges on the same Decision for the actor Subject.
     */
    public function resolve(Subject $subject, Authorizable $actor, AclScope $scope): ?Decision
    {
        // The Gate is user-scoped, not subject-scoped: only evaluate against the
        // actor's own Subject so role/team Subjects (which the Gate cannot answer
        // for) do not double-count. A non-actor subject contributes nothing.
        if (!$this->isActorSubject($subject, $actor)) {
            return null;
        }

        $granted = null;

        foreach (GateConfig::abilityMap() as $levelValue => $ability) {
            $level = GrantLevel::tryFrom($levelValue);

            if ($level === null) {
                continue;
            }

            if (!$this->gateAllows($actor, $ability, [$this->contextFor($scope)])) {
                continue;
            }

            $granted = $granted === null ? $level : $granted->max($level);
        }

        if ($granted === null) {
            return null;
        }

        return Decision::allow($granted);
    }

    /**
     * The super-admin override: an Effect::Allow when the host's super ability is
     * granted, else null (never a deny — the Gate lane only widens access here, the
     * deny veto stays the ACL's job).
     */
    public function override(Authorizable $actor, AclScope $scope): ?Effect
    {
        if ($this->gateAllows($actor, GateConfig::superAbility(), [$this->contextFor($scope)])) {
            return Effect::Allow;
        }

        return null;
    }

    /**
     * The Gate exposes no enumerable role/team subjects — abilities are evaluated
     * against the user, not listed — so the subject-set is the actor itself. A host
     * whose grants target Gate-derived roles should use the Spatie/Azgard lane.
     *
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array
    {
        if (!$actor instanceof Model) {
            return [];
        }

        return [Subject::ofModel($actor)];
    }

    /**
     * May the actor create nodes in the host workspace? Delegates to the configured
     * create ability, passing the workspace [type, id] as the ability arguments so
     * the host's gate/policy can decide per workspace.
     */
    public function canCreateInWorkspace(Authorizable $actor, string $workspaceType, int|string $workspaceId): bool
    {
        return $this->gateAllows($actor, GateConfig::createAbility(), [$workspaceType, $workspaceId]);
    }

    /**
     * Run a Gate ability for the actor, node-blind, swallowing a missing-ability
     * exception as "not allowed" so an unconfigured host gate never throws into the
     * resolver.
     *
     * @param list<mixed> $arguments
     */
    private function gateAllows(Authorizable $actor, string $ability, array $arguments): bool
    {
        return Gate::forUser($actor)->allows($ability, $arguments);
    }

    /**
     * The Node-BLIND context handed to a Gate ability: the panel + workspace
     * coordinates from the AclScope, never a Node. A host gate receives exactly the
     * workspace it authorises within (the contract's identity-agnostic boundary).
     *
     * @return array{panel: string, workspace_type: ?string, workspace_id: int|string|null}
     */
    private function contextFor(AclScope $scope): array
    {
        return [
            'panel' => $scope->panel,
            'workspace_type' => $scope->workspaceType,
            'workspace_id' => $scope->workspaceId,
        ];
    }

    private function isActorSubject(Subject $subject, Authorizable $actor): bool
    {
        if (!$actor instanceof Model) {
            return false;
        }

        return $subject->equals(Subject::ofModel($actor));
    }
}
