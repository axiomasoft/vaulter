<?php

declare(strict_types=1);

namespace Vaulter\Support;

use Vaulter\Drivers\Permission\GatePermissionResolver;
use Vaulter\Enums\GrantLevel;

/**
 * Typed accessors over config('vaulter.gate.*') — the Gate host-role lane's ability
 * map (master plan §6 / H.2). The map couples a Vaulter GrantLevel to a host Gate
 * ability string, so the {@see GatePermissionResolver}
 * stays Node-blind and the host's ability names live entirely in config (cache-safe
 * — plain strings, no closures).
 *
 * Sane defaults ('vaulter.view'/'vaulter.edit'/…) let a host wire policies named to
 * match without any config; override the whole map to point at the host's own
 * ability vocabulary.
 */
final class GateConfig
{
    /**
     * The GrantLevel-value → Gate-ability-string map. The resolver asks the Gate
     * each mapped ability and folds the HIGHEST allowed level into the Decision, so
     * a host need only map the levels it actually gates.
     *
     * @return array<string, string>
     */
    public static function abilityMap(): array
    {
        $value = config('vaulter.gate.abilities', self::defaults());

        if (!is_array($value)) {
            return self::defaults();
        }

        $map = [];

        foreach ($value as $level => $ability) {
            if (is_string($level) && is_string($ability) && $ability !== '') {
                $map[$level] = $ability;
            }
        }

        return $map === [] ? self::defaults() : $map;
    }

    /**
     * The super-admin ability that, when granted, short-circuits the actor to full
     * access (the override lane). Defaults to 'vaulter.super'.
     */
    public static function superAbility(): string
    {
        $value = config('vaulter.gate.super_ability', 'vaulter.super');

        return is_string($value) && $value !== '' ? $value : 'vaulter.super';
    }

    /**
     * The ability checked by canCreateInWorkspace, passed the workspace [type, id].
     * Defaults to 'vaulter.create'.
     */
    public static function createAbility(): string
    {
        $value = config('vaulter.gate.create_ability', 'vaulter.create');

        return is_string($value) && $value !== '' ? $value : 'vaulter.create';
    }

    /**
     * The built-in level → ability defaults, mapping each ranked GrantLevel to a
     * 'vaulter.<verb>' ability a host policy can implement out of the box.
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
