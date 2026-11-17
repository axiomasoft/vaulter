<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Drivers\GatePermissionDriver;
use Vaulter\Drive\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Drive\Access\Roles\RoleRegistry;

/**
 * Immutable compiled access options for `vaulter-drive.access.*`.
 */
#[Api(since: '1.0')]
final readonly class AccessOptions
{
    public const string PERMISSION_DRIVER_KEY = 'vaulter-drive.access.permission_driver';

    public const string PERMISSION_DRIVERS_KEY = 'vaulter-drive.access.permission_drivers';

    /** @var array<string, string> */
    private const array DEFAULT_PERMISSION_DRIVERS = [
        'database' => DatabasePermissionDriver::class,
        'gate' => GatePermissionDriver::class,
        'spatie' => SpatiePermissionDriver::class,
        'null' => 'Vaulter\Drive\Testing\NullPermissionDriver',
    ];

    /** @var array<string, string> */
    private const array DEFAULT_ROLE_ABILITIES = [
        RoleRegistry::PREVIEWER => 'vaulter.preview',
        RoleRegistry::READER => 'vaulter.view',
        RoleRegistry::COMMENTER => 'vaulter.comment',
        RoleRegistry::WRITER => 'vaulter.edit',
        RoleRegistry::ORGANIZER => 'vaulter.organize',
        RoleRegistry::OWNER => 'vaulter.manage',
    ];

    /**
     * @param array<string, string> $permissionDrivers
     * @param array<string, string> $gateAbilities
     * @param array<string, string> $spatiePermissions
     */
    public function __construct(
        public string $permissionDriver = 'database',
        public array $permissionDrivers = self::DEFAULT_PERMISSION_DRIVERS,
        public array $gateAbilities = self::DEFAULT_ROLE_ABILITIES,
        public string $gateSuperAbility = 'vaulter.super',
        public string $gateCreateAbility = 'vaulter.create',
        public array $spatiePermissions = self::DEFAULT_ROLE_ABILITIES,
        public string $spatieSuperPermission = 'vaulter.super',
        public string $spatieCreatePermission = 'vaulter.create',
        public bool $spatieTeams = false,
        public string $spatieRoleSubjectType = 'role',
        public string $spatieTeamSubjectType = 'team',
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, ['permission_driver', 'permission_drivers', 'gate', 'spatie'], $path);

        $driver = self::nonEmptyString($raw['permission_driver'] ?? 'database', $path.'.permission_driver', 'database', $validator);
        $drivers = self::parseDriverMap($raw['permission_drivers'] ?? self::DEFAULT_PERMISSION_DRIVERS, $path.'.permission_drivers', $validator);

        $gateRaw = $raw['gate'] ?? [];
        if (!is_array($gateRaw)) {
            $validator->add($path.'.gate', 'must be an array.');
            $gateRaw = [];
        } else {
            $validator->rejectUnknownKeys($gateRaw, ['abilities', 'super_ability', 'create_ability'], $path.'.gate');
        }

        $gateAbilities = self::parseRoleMap(
            $gateRaw['abilities'] ?? self::DEFAULT_ROLE_ABILITIES,
            $path.'.gate.abilities',
            $validator,
        );
        $gateSuperAbility = self::nonEmptyString($gateRaw['super_ability'] ?? 'vaulter.super', $path.'.gate.super_ability', 'vaulter.super', $validator);
        $gateCreateAbility = self::nonEmptyString($gateRaw['create_ability'] ?? 'vaulter.create', $path.'.gate.create_ability', 'vaulter.create', $validator);

        $spatieRaw = $raw['spatie'] ?? [];
        if (!is_array($spatieRaw)) {
            $validator->add($path.'.spatie', 'must be an array.');
            $spatieRaw = [];
        } else {
            $validator->rejectUnknownKeys($spatieRaw, ['permissions', 'super_permission', 'create_permission', 'teams', 'role_subject_type', 'team_subject_type'], $path.'.spatie');
        }

        $spatiePermissions = self::parseRoleMap(
            $spatieRaw['permissions'] ?? self::DEFAULT_ROLE_ABILITIES,
            $path.'.spatie.permissions',
            $validator,
        );
        $spatieSuperPermission = self::nonEmptyString($spatieRaw['super_permission'] ?? 'vaulter.super', $path.'.spatie.super_permission', 'vaulter.super', $validator);
        $spatieCreatePermission = self::nonEmptyString($spatieRaw['create_permission'] ?? 'vaulter.create', $path.'.spatie.create_permission', 'vaulter.create', $validator);
        $spatieTeams = (bool) ($spatieRaw['teams'] ?? false);
        $spatieRoleSubjectType = self::nonEmptyString($spatieRaw['role_subject_type'] ?? 'role', $path.'.spatie.role_subject_type', 'role', $validator);
        $spatieTeamSubjectType = self::nonEmptyString($spatieRaw['team_subject_type'] ?? 'team', $path.'.spatie.team_subject_type', 'team', $validator);

        return new self(
            permissionDriver: $driver,
            permissionDrivers: $drivers,
            gateAbilities: $gateAbilities,
            gateSuperAbility: $gateSuperAbility,
            gateCreateAbility: $gateCreateAbility,
            spatiePermissions: $spatiePermissions,
            spatieSuperPermission: $spatieSuperPermission,
            spatieCreatePermission: $spatieCreatePermission,
            spatieTeams: $spatieTeams,
            spatieRoleSubjectType: $spatieRoleSubjectType,
            spatieTeamSubjectType: $spatieTeamSubjectType,
        );
    }

    private static function nonEmptyString(mixed $value, string $path, string $default, ConfigurationValidator $validator): string
    {
        if (!is_string($value) || $value === '') {
            $validator->add($path, 'must be a non-empty string.');

            return $default;
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function parseDriverMap(mixed $raw, string $path, ConfigurationValidator $validator): array
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be a class map.');

            return self::DEFAULT_PERMISSION_DRIVERS;
        }

        $map = [];
        foreach ($raw as $key => $class) {
            if (!is_string($key) || $key === '' || !is_string($class) || $class === '') {
                $validator->add($path, 'Invalid permission driver class map.');

                return self::DEFAULT_PERMISSION_DRIVERS;
            }
            $map[$key] = $class;
        }

        return $map;
    }

    /** @return array<string, string> */
    private static function parseRoleMap(mixed $raw, string $path, ConfigurationValidator $validator): array
    {
        if (!is_array($raw)) {
            return self::DEFAULT_ROLE_ABILITIES;
        }

        $roles = new RoleRegistry;
        $map = self::DEFAULT_ROLE_ABILITIES;
        foreach ($raw as $key => $name) {
            if (!is_string($key) || !$roles->has($key)) {
                $validator->add($path, sprintf('Unknown role [%s].', is_string($key) ? $key : (string) $key));

                continue;
            }
            if (is_string($name) && $name !== '') {
                $map[$key] = $name;
            }
        }

        return $map;
    }
}
