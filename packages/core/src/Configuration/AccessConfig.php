<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Exceptions\Access\UnknownRoleException;
/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */

use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

final readonly class AccessConfig
{
    public function __construct(private Repository $config) {}

    public function permissionDriver(): string
    {
        $value = $this->config->get('vaulter.access.permission_driver', 'database');

        if (!is_string($value) || $value === '') {
            throw new InvalidConfigurationException('[Vaulter] vaulter.access.permission_driver must be a nonempty driver key.');
        }

        return $value;
    }

    /** @return array<string, string> */
    public function permissionDrivers(): array
    {
        $drivers = $this->config->get('vaulter.access.permission_drivers', []);
        if (!is_array($drivers)) {
            throw new InvalidConfigurationException('[Vaulter] vaulter.access.permission_drivers must be a class map.');
        }
        foreach ($drivers as $key => $class) {
            if (!is_string($key) || !is_string($class)) {
                throw new InvalidConfigurationException('[Vaulter] Invalid permission driver class map.');
            }
        }

        /** @var array<string, string> $drivers */
        return $drivers;
    }

    /** @return array<string, string> */
    public function gateAbilityMap(): array
    {
        $value = $this->config->get('vaulter.access.gate.abilities', $this->gateDefaults());

        if (!is_array($value)) {
            return $this->gateDefaults();
        }

        $map = [];

        foreach ($value as $role => $ability) {
            if (is_string($role) && is_string($ability) && $ability !== '') {
                $map[$this->roleKey($role)] = $ability;
            }
        }

        return $map === [] ? $this->gateDefaults() : $map;
    }

    public function gateSuperAbility(): string
    {
        $value = $this->config->get('vaulter.access.gate.super_ability', 'vaulter.super');

        return is_string($value) && $value !== '' ? $value : 'vaulter.super';
    }

    public function gateCreateAbility(): string
    {
        $value = $this->config->get('vaulter.access.gate.create_ability', 'vaulter.create');

        return is_string($value) && $value !== '' ? $value : 'vaulter.create';
    }

    /** @return array<string, string> */
    private function gateDefaults(): array
    {
        return [
            RoleRegistry::PREVIEWER => 'vaulter.preview',
            RoleRegistry::READER => 'vaulter.view',
            RoleRegistry::COMMENTER => 'vaulter.comment',
            RoleRegistry::WRITER => 'vaulter.edit',
            RoleRegistry::ORGANIZER => 'vaulter.organize',
            RoleRegistry::OWNER => 'vaulter.manage',
        ];
    }

    /** @return array<string, string> */
    public function spatiePermissionMap(): array
    {
        $value = $this->config->get('vaulter.access.spatie.permissions', $this->spatieDefaults());

        if (!is_array($value)) {
            return $this->spatieDefaults();
        }

        $map = [];

        foreach ($value as $role => $permission) {
            if (is_string($role) && is_string($permission) && $permission !== '') {
                $map[$this->roleKey($role)] = $permission;
            }
        }

        return $map === [] ? $this->spatieDefaults() : $map;
    }

    public function spatieSuperPermission(): string
    {
        $value = $this->config->get('vaulter.access.spatie.super_permission', 'vaulter.super');

        return is_string($value) && $value !== '' ? $value : 'vaulter.super';
    }

    public function spatieCreatePermission(): string
    {
        $value = $this->config->get('vaulter.access.spatie.create_permission', 'vaulter.create');

        return is_string($value) && $value !== '' ? $value : 'vaulter.create';
    }

    public function spatieTeamsEnabled(): bool
    {
        // The host's own spatie config is authoritative; fall back to the Vaulter
        // override only when spatie config is absent (e.g. in a thin test app).
        $spatie = $this->config->get('permission.teams');

        if (is_bool($spatie)) {
            return $spatie;
        }

        return (bool) $this->config->get('vaulter.access.spatie.teams', false);
    }

    public function spatieRoleSubjectType(): string
    {
        $value = $this->config->get('vaulter.access.spatie.role_subject_type', 'role');

        return is_string($value) && $value !== '' ? $value : 'role';
    }

    public function spatieTeamSubjectType(): string
    {
        $value = $this->config->get('vaulter.access.spatie.team_subject_type', 'team');

        return is_string($value) && $value !== '' ? $value : 'team';
    }

    /** @return array<string, string> */
    private function spatieDefaults(): array
    {
        return [
            RoleRegistry::PREVIEWER => 'vaulter.preview',
            RoleRegistry::READER => 'vaulter.view',
            RoleRegistry::COMMENTER => 'vaulter.comment',
            RoleRegistry::WRITER => 'vaulter.edit',
            RoleRegistry::ORGANIZER => 'vaulter.organize',
            RoleRegistry::OWNER => 'vaulter.manage',
        ];
    }

    /**
     * A role map key must name a registered role; a typo would otherwise silently
     * grant nothing.
     *
     * @throws UnknownRoleException
     */
    private function roleKey(string $key): string
    {
        if (!(new RoleRegistry)->has($key)) {
            throw UnknownRoleException::for($key);
        }

        return $key;
    }
}
