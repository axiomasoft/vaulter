<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Drivers\Permission\SpatiePermissionResolver;
use Vaulter\Enums\GrantLevel;

/**
 * Typed accessors over config('vaulter.spatie.*') — the spatie/laravel-permission
 * host-role lane's permission map (master plan §6 / H.2). The map couples a Vaulter
 * GrantLevel to a spatie permission NAME, so the
 * {@see SpatiePermissionResolver} resolves
 * `$actor->hasPermissionTo($name)` (team-scoped) without Vaulter knowing the host's
 * permission vocabulary. Cache-safe — plain strings, no closures.
 */
final class SpatieConfig
{
    /**
     * The GrantLevel-value → spatie-permission-name map. The resolver asks each
     * mapped permission (team-scoped) and folds the HIGHEST allowed level into the
     * Decision.
     *
     * @return array<string, string>
     */
    public static function permissionMap(): array
    {
        $value = config('vaulter.spatie.permissions', self::defaults());

        if (!is_array($value)) {
            return self::defaults();
        }

        $map = [];

        foreach ($value as $level => $permission) {
            if (is_string($level) && is_string($permission) && $permission !== '') {
                $map[$level] = $permission;
            }
        }

        return $map === [] ? self::defaults() : $map;
    }

    /**
     * The super-role / super-permission whose presence short-circuits the actor to
     * full access (the override lane). Defaults to 'vaulter.super'. Resolved as a
     * permission name via hasPermissionTo so a host may grant it directly or through
     * a super-role that owns it.
     */
    public static function superPermission(): string
    {
        $value = config('vaulter.spatie.super_permission', 'vaulter.super');

        return is_string($value) && $value !== '' ? $value : 'vaulter.super';
    }

    /**
     * The permission checked by canCreateInWorkspace (team-scoped to the workspace).
     * Defaults to 'vaulter.create'.
     */
    public static function createPermission(): string
    {
        $value = config('vaulter.spatie.create_permission', 'vaulter.create');

        return is_string($value) && $value !== '' ? $value : 'vaulter.create';
    }

    /**
     * Whether the host runs spatie's teams feature. When true the resolver runs the
     * full v8 team-switch dance (setPermissionsTeamId → check → restore) around
     * every scoped check and contributes the actor's roles as role/team Subjects.
     */
    public static function teamsEnabled(): bool
    {
        // The host's own spatie config is authoritative; fall back to the Vaulter
        // override only when spatie config is absent (e.g. in a thin test app).
        $spatie = config('permission.teams');

        if (is_bool($spatie)) {
            return $spatie;
        }

        return (bool) config('vaulter.spatie.teams', false);
    }

    /**
     * The morph alias the resolver tags role Subjects with (so a v_node_grants row
     * granted to subject_type 'role' matches). Defaults to 'role'.
     */
    public static function roleSubjectType(): string
    {
        $value = config('vaulter.spatie.role_subject_type', 'role');

        return is_string($value) && $value !== '' ? $value : 'role';
    }

    /**
     * The morph alias the resolver tags team Subjects with. Defaults to 'team'.
     */
    public static function teamSubjectType(): string
    {
        $value = config('vaulter.spatie.team_subject_type', 'team');

        return is_string($value) && $value !== '' ? $value : 'team';
    }

    /**
     * The built-in level → permission defaults.
     *
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        return [
            GrantLevel::Reader->value => 'vaulter.view',
            GrantLevel::Commenter->value => 'vaulter.comment',
            GrantLevel::Writer->value => 'vaulter.edit',
            GrantLevel::Organizer->value => 'vaulter.organize',
            GrantLevel::Owner->value => 'vaulter.manage',
        ];
    }
}
