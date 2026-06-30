<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Contracts\Access\AzgardGuard;
use Vaulter\Drivers\Permission\AzgardPermissionResolver;
use Vaulter\Enums\Access\Ability;

/**
 * Typed accessors over config('vaulter.azgard.*') — the AzGuard host-role lane's
 * Ability→permission map (master plan §6 / H.2). Unlike the Gate/Spatie maps (keyed
 * by GrantLevel), AzGuard is action-centric, so the {@see AzgardPermissionResolver}
 * maps a requested {@see Ability} to an AzGuard permission key (e.g. View →
 * documents.view) and asks the {@see AzgardGuard} that permission.
 * Plain strings — cache-safe, no closures.
 */
final class AzgardConfig
{
    /**
     * The Ability-value → AzGuard-permission-key map. The resolver maps the requested
     * ability through this before asking the guard; an ability with no mapping
     * contributes no decision.
     *
     * @return array<string, string>
     */
    public static function permissionMap(): array
    {
        $value = config('vaulter.azgard.permissions', self::defaults());

        if (!is_array($value)) {
            return self::defaults();
        }

        $map = [];

        foreach ($value as $ability => $permission) {
            if (is_string($ability) && is_string($permission) && $permission !== '') {
                $map[$ability] = $permission;
            }
        }

        return $map === [] ? self::defaults() : $map;
    }

    /**
     * The AzGuard permission gating node creation (the canCreateInWorkspace lane),
     * checked via hasPermissionIn against the workspace morph. Defaults to
     * 'documents.create'.
     */
    public static function createPermission(): string
    {
        $value = config('vaulter.azgard.create_permission', 'documents.create');

        return is_string($value) && $value !== '' ? $value : 'documents.create';
    }

    /**
     * The AzGuard permission key for a single ability, or null when the ability is
     * unmapped. The lookup the resolver runs per requested ability.
     */
    public static function permissionFor(Ability $ability): ?string
    {
        return self::permissionMap()[$ability->value] ?? null;
    }

    /**
     * The built-in Ability → AzGuard-permission defaults (master plan §6). View/
     * Download/Comment/Edit/Create map to their own verbs; Move/Copy fold into
     * documents.update (re-parent = an update), Trash/Delete into documents.delete,
     * and Share/Manage into documents.manage.
     *
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        return [
            Ability::View->value => 'documents.view',
            Ability::Download->value => 'documents.download',
            Ability::Comment->value => 'documents.comment',
            Ability::Edit->value => 'documents.update',
            Ability::Create->value => 'documents.create',
            Ability::Move->value => 'documents.update',
            Ability::Copy->value => 'documents.update',
            Ability::Trash->value => 'documents.delete',
            Ability::Delete->value => 'documents.delete',
            Ability::Restore->value => 'documents.delete',
            Ability::Share->value => 'documents.manage',
            Ability::Manage->value => 'documents.manage',
        ];
    }
}
