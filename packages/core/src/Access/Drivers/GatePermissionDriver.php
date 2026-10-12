<?php

declare(strict_types=1);

namespace Vaulter\Access\Drivers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\AccessScope;
use Vaulter\Access\ValueObjects\Decision;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Configuration\AccessConfig;
use Vaulter\Contracts\Access\PermissionDriver;
use Vaulter\Enums\Access\Effect;
use Vaulter\ValueObjects\Access\OwnerRef;

/**
 * The Laravel-Gate host-role driver — a zero-extra-dependency
 * lane, because the Gate ships with the framework. It bridges a host that already
 * authorises through Gate abilities / policies into Vaulter WITHOUT teaching Vaulter
 * the host's abilities: every coupling lives in a configurable ability map.
 *
 *   - subjectsFor(actor) = [Subject(actor)] — the Gate exposes no role/team subjects
 *     (abilities are evaluated against the user, not enumerated), so only the actor's
 *     own identity joins the subject-set; v_node_grants addressed to a role/team are
 *     simply not matched by this lane (use the Spatie/host authorization lane for that).
 *   - override(actor, scope) = a configurable super-admin ability (default
 *     'vaulter.super'); when the Gate allows it the actor short-circuits to full
 *     access, else this lane defers (null) — never a block.
 *   - resolve(subject, actor, scope) = per role, the Gate is asked the mapped
 *     ability ('vaulter.preview'/'vaulter.view'/'vaulter.edit'/…); the allowed roles
 *     are unioned (the built-in roles nest, so the union is the role that covers the
 *     others). The Gate check is Node-BLIND: it receives the AccessScope's
 *     panel + workspace coordinates, never a Node, so the contract stays swappable.
 *   - canCreateIn = the configured create ability, passed the full node-blind scope context.
 *
 * Every Gate check goes through Gate::forUser($actor) so the driver is stateless and
 * never depends on the currently-authenticated user.
 */
final class GatePermissionDriver implements PermissionDriver
{
    public function __construct(
        private readonly AccessConfig $accessConfig,
        private readonly RoleRegistry $roles,
    ) {}

    /**
     * Each mapped role whose Gate ability the actor holds counts; the Decision is
     * their union. The Gate is per-user, so only the actor's own Subject is answered.
     */
    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        // The Gate is user-scoped, not subject-scoped: only evaluate against the
        // actor's own Subject so role/team Subjects (which the Gate cannot answer
        // for) do not double-count. A non-actor subject contributes nothing.
        if (!$this->isActorSubject($subject, $actor)) {
            return null;
        }

        $granted = [];
        foreach ($this->accessConfig->gateAbilityMap() as $role => $ability) {
            if ($this->gateAllows($actor, $ability, [$this->contextFor($scope)])) {
                $granted[] = $this->roles->latest($role);
            }
        }

        return Decision::union($granted);
    }

    /**
     * The super-admin override: an Effect::Allow when the host's super ability is
     * granted, else null (never a block — the Gate lane only widens access).
     */
    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        if ($this->gateAllows($actor, $this->accessConfig->gateSuperAbility(), [$this->contextFor($scope)])) {
            return Effect::Allow;
        }

        return null;
    }

    /**
     * The Gate exposes no enumerable role/team subjects — abilities are evaluated
     * against the user, not listed — so the subject-set is the actor itself. A host
     * whose grants target Gate-derived roles should use the Spatie lane.
     *
     * Because this lane does NOT implement
     * {@see EnumeratesRoleSubjects}, a v_node_grants row
     * addressed to a role/team is inert under it; writing one fails loud
     * (GrantNodeAccessAction warns, and throws outside production).
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
     * create ability, passing the full node-blind scope context as the ability argument so
     * the host's gate/policy can decide per workspace.
     */
    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        return $this->gateAllows($actor, $this->accessConfig->gateCreateAbility(), [$this->contextFor($scope)]);
    }

    /**
     * Run a Gate ability for the actor, node-blind (this is a plain
     * delegation, there is no try/catch here — `Gate::allows()` itself already
     * returns false, never throws, for an ability the host has not defined, which
     * is what keeps an unconfigured host gate fail-closed).
     *
     * @param list<mixed> $arguments
     */
    private function gateAllows(Authorizable $actor, string $ability, array $arguments): bool
    {
        return Gate::forUser($actor)->allows($ability, $arguments);
    }

    /** @return array{profile:string, drive_owner: OwnerRef, drive_id:int|string, tenant_id:int|string|null} */
    private function contextFor(AccessScope $scope): array
    {
        return [
            'profile' => $scope->profile,
            'drive_owner' => $scope->driveOwner,
            'drive_id' => $scope->driveId,
            'tenant_id' => $scope->tenantId,
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
