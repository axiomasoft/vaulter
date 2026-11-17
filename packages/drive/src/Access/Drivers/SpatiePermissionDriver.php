<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access\Drivers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use Vaulter\Drive\Access\Roles\Role;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Access\ValueObjects\Decision;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Configuration\AccessOptions;
use Vaulter\Drive\Contracts\Access\EnumeratesRoleSubjects;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Enums\Access\Effect;

/**
 * The spatie/laravel-permission host-role driver. It
 * bridges a host running spatie roles/permissions — INCLUDING the v8 teams feature —
 * into Vaulter, and ENCAPSULATES THE ENTIRE v8 TEAM-SWITCH DANCE here so it never
 * leaks into the Vaulter-owned AccessResolver:
 *
 *   - subjectsFor(actor) = the actor + its role Subjects (+ its team Subject when
 *     teams are enabled), so a v_node_grants row granted to a role/team matches;
 *   - resolve(subject, actor, scope) = per role, the mapped spatie permission is
 *     checked via $actor->hasPermissionTo($name) and the held roles are unioned — wrapped in
 *     setPermissionsTeamId($scope->driveOwner->id) so the check is team-scoped, then
 *     the previous team id and the actor's loaded relations are RESTORED;
 *   - override = a configurable super-permission (a super-role owns it);
 *   - canCreateIn = hasPermissionTo(create) in the workspace's team context.
 *
 * spatie/laravel-permission is an OPTIONAL host dependency: it ships as require-dev
 * (for this package's own tests) only, and every runtime touch of the spatie API is
 * guarded by isAvailable() (class_exists on PermissionRegistrar) so vaulter-core has
 * NO hard dependency on it. A host that selects this driver without installing the
 * package gets a safe deny (null/false), not a fatal error.
 *
 * v8 API (verified against spatie/laravel-permission 8.x): PermissionRegistrar::
 * {get,set}PermissionsTeamId() drive the team context; forgetCachedPermissions()
 * resets the permission cache after a mutation. The team-switch is symmetric: the
 * incoming team id is captured, set to the scope's workspace, and restored in a
 * finally — so concurrent panels/workspaces never bleed into each other.
 */
#[Internal]
final class SpatiePermissionDriver implements EnumeratesRoleSubjects, PermissionDriver
{
    public function __construct(
        private readonly AccessOptions $accessOptions,
        private readonly RoleRegistry $roles,
    ) {}

    public function resolve(Subject $subject, Authorizable $actor, AccessScope $scope): ?Decision
    {
        if (!$this->isAvailable() || !$actor instanceof Model) {
            return null;
        }

        // The permission check is user-scoped: only evaluate the actor's own
        // Subject so role/team Subjects (already matched by v_node_grants via
        // subjectsFor) do not double-resolve through the permission lane.
        if (!$subject->equals(Subject::ofModel($actor))) {
            return null;
        }

        /** @var list<Role> $granted */
        $granted = $this->withTeam($scope, function () use ($actor): array {
            $roles = [];
            foreach ($this->accessOptions->spatiePermissions as $role => $permission) {
                if ($this->actorHasPermission($actor, $permission)) {
                    $roles[] = $this->roles->latest($role);
                }
            }

            return $roles;
        });

        return Decision::union($granted);
    }

    public function override(Authorizable $actor, AccessScope $scope): ?Effect
    {
        if (!$this->isAvailable() || !$actor instanceof Model) {
            return null;
        }

        $isSuper = $this->withTeam(
            $scope,
            fn (): bool => $this->actorHasPermission($actor, $this->accessOptions->spatieSuperPermission),
        );

        return $isSuper ? Effect::Allow : null;
    }

    /**
     * The actor plus its spatie roles (and team) as Subjects, so a v_node_grants row
     * addressed to a role/team resolves. Without spatie installed, only the actor.
     *
     * @return list<Subject>
     */
    public function subjectsFor(Authorizable $actor): array
    {
        if (!$actor instanceof Model) {
            return [];
        }

        $subjects = [Subject::ofModel($actor)];

        if (!$this->isAvailable()) {
            return $subjects;
        }

        foreach ($this->roleKeysOf($actor) as $roleKey) {
            $subjects[] = new Subject(type: $this->accessOptions->spatieRoleSubjectType, id: $roleKey);
        }

        if ($this->teamsEnabled()) {
            $teamId = $this->currentTeamId();

            if ($teamId !== null) {
                $subjects[] = new Subject(type: $this->accessOptions->spatieTeamSubjectType, id: $teamId);
            }
        }

        return $subjects;
    }

    /** @internal
     * @return list<Subject>
     */
    public function subjectsForIn(Authorizable $actor, AccessScope $scope): array
    {
        if (!$this->isAvailable() || !$actor instanceof Model) {
            return $this->subjectsFor($actor);
        }

        return $this->withTeam($scope, function () use ($actor): array {
            $roles = $actor->getRelations();
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            try {
                return $this->subjectsFor($actor);
            } finally {
                $actor->setRelations($roles);
            }
        });
    }

    /**
     * The consumer-facing cache-reset seam: after a host mutates an
     * actor's roles/permissions OUTSIDE spatie's own assign/remove helpers (which
     * already reset relations), it MUST call this so a stale, team-scoped permission
     * cache cannot grant a revoked role on the next resolve. Encapsulated here so the
     * team-switch knowledge never leaks into Vaulter core — the host calls one method.
     */
    public static function forgetCachedPermissions(Authorizable $actor): void
    {
        if (!class_exists(PermissionRegistrar::class)) {
            return;
        }

        if ($actor instanceof Model) {
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }

        /** @var PermissionRegistrar $registrar */
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
    }

    public function canCreateIn(Authorizable $actor, AccessScope $scope): bool
    {
        if (!$this->isAvailable() || !$actor instanceof Model) {
            return false;
        }

        return $this->withTeam(
            $scope,
            fn (): bool => $this->actorHasPermission($actor, $this->accessOptions->spatieCreatePermission),
        );
    }

    /**
     * Run $callback inside the spatie team context of the scope's workspace, then
     * restore the previous team id and reset the actor's loaded role/permission
     * relations — THE v8 TEAM-SWITCH DANCE, fully contained here. When teams are
     * disabled the callback runs unchanged (no global state touched).
     *
     * The restore runs in a finally so a throwing check can never leave a leaked
     * team id behind for the next panel/workspace.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn $callback
     * @return TReturn
     */
    private function withTeam(AccessScope $scope, callable $callback): mixed
    {
        if (!$this->teamsEnabled()) {
            return $callback();
        }

        $registrar = $this->registrar();
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($scope->driveOwner->id);

        try {
            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Check a single spatie permission for the actor, fresh: the actor's loaded
     * roles/permissions are unset first so a permission cached under a DIFFERENT
     * team id (from a previous scoped check) cannot leak across teams. Returns false
     * for an unknown permission name (spatie throws PermissionDoesNotExist — a host
     * that has not defined the mapped name simply has no such grant).
     */
    private function actorHasPermission(Model $actor, string $permission): bool
    {
        // The actor must use spatie's HasRoles/HasPermissions trait — guard the
        // dynamic call so a host model lacking it (e.g. a bare User) is a clean "no
        // grant", not a fatal, and so the optional spatie API is never hard-typed.
        if (!method_exists($actor, 'hasPermissionTo')) {
            return false;
        }

        $actor->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return (bool) $actor->hasPermissionTo($permission);
        } catch (Throwable) {
            // An undefined permission name (PermissionDoesNotExist) or any spatie
            // lookup failure is "no grant", never a fatal into the resolver.
            return false;
        }
    }

    /**
     * The actor's spatie role keys, used to build role Subjects. Reads the role model
     * PRIMARY KEYS — the same handle every other subject uses (a user's key, a team's
     * key), so a role grant's subject_id fits the typed id-strategy column on strict
     * databases (a role NAME would be rejected by a bigint/uuid subject_id on Postgres
     * or MySQL). The `roles` relation is the SAME team-scoped collection getRoleNames()
     * reads, so team isolation is preserved; the read is side-effect free.
     *
     * @return list<int|string>
     */
    private function roleKeysOf(Model $actor): array
    {
        if (!method_exists($actor, 'getRoleNames')) {
            return [];
        }

        /** @var iterable<Model> $roles */
        $roles = $actor->getAttribute('roles');

        $keys = [];

        foreach ($roles as $role) {
            /** @var int|string $key */
            $key = $role->getKey();
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * The currently-active spatie team id (the workspace a "shared with me" pass is
     * evaluated in). Null when teams are off or no team is set.
     */
    private function currentTeamId(): int|string|null
    {
        return $this->registrar()->getPermissionsTeamId();
    }

    private function registrar(): PermissionRegistrar
    {
        /** @var PermissionRegistrar $registrar */
        $registrar = app(PermissionRegistrar::class);

        return $registrar;
    }

    /**
     * Is spatie/laravel-permission installed? Every runtime touch of the spatie API
     * is gated by this so vaulter-core carries no hard dependency (the package is
     * require-dev only, for this suite's own tests).
     */
    private function teamsEnabled(): bool
    {
        if ($this->accessOptions->spatieTeams) {
            return true;
        }

        return $this->isAvailable() && app()->bound(PermissionRegistrar::class) && $this->registrar()->teams;
    }

    private function isAvailable(): bool
    {
        return class_exists(PermissionRegistrar::class);
    }
}
