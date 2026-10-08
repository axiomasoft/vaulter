<?php

declare(strict_types=1);

namespace Vaulter\Access\Roles;

use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\UnknownRoleException;

/**
 * The built-in roles. Keys are stable; every version ever published stays here so a
 * stored grant always resolves to the abilities it was given.
 *
 *   previewer  view
 *   reader     view, download
 *   commenter  reader + comment
 *   writer     commenter + edit, create, move, copy, trash, restore
 *   organizer  writer + share
 *   owner      organizer + manage (access), delete (permanent)
 */
final class RoleRegistry
{
    public const string PREVIEWER = 'previewer';

    public const string READER = 'reader';

    public const string COMMENTER = 'commenter';

    public const string WRITER = 'writer';

    public const string ORGANIZER = 'organizer';

    public const string OWNER = 'owner';

    /** @var array<string, array<int, Role>>|null */
    private static ?array $roles = null;

    /**
     * @throws UnknownRoleException
     */
    public function get(string $key, int $version): Role
    {
        return self::roles()[$key][$version] ?? throw UnknownRoleException::for($key, $version);
    }

    /**
     * The newest version of a role; new grants always use it.
     *
     * @throws UnknownRoleException
     */
    public function latest(string $key): Role
    {
        $versions = self::roles()[$key] ?? throw UnknownRoleException::for($key);

        // Versions are registered in ascending order.
        $latest = null;
        foreach ($versions as $role) {
            $latest = $role;
        }

        return $latest ?? throw UnknownRoleException::for($key);
    }

    public function has(string $key, ?int $version = null): bool
    {
        return $version === null ? isset(self::roles()[$key]) : isset(self::roles()[$key][$version]);
    }

    /**
     * The newest version of every role, narrowest first.
     *
     * @return list<Role>
     */
    public function all(): array
    {
        return array_map(fn (string $key): Role => $this->latest($key), $this->keys());
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::roles());
    }

    /** @return array<string, array<int, Role>> */
    private static function roles(): array
    {
        if (self::$roles !== null) {
            return self::$roles;
        }

        $reader = [Ability::View, Ability::Download];
        $commenter = [...$reader, Ability::Comment];
        $writer = [...$commenter, Ability::Edit, Ability::Create, Ability::Move, Ability::Copy, Ability::Trash, Ability::Restore];
        $organizer = [...$writer, Ability::Share];
        $owner = [...$organizer, Ability::Manage, Ability::Delete];

        $roles = [];
        foreach ([
            self::PREVIEWER => [Ability::View],
            self::READER => $reader,
            self::COMMENTER => $commenter,
            self::WRITER => $writer,
            self::ORGANIZER => $organizer,
            self::OWNER => $owner,
        ] as $key => $abilities) {
            $roles[$key][1] = new Role(key: $key, version: 1, abilities: $abilities);
        }

        return self::$roles = $roles;
    }
}
