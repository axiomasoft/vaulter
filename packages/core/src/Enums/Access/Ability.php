<?php

declare(strict_types=1);

namespace Vaulter\Enums\Access;

use Vaulter\Attributes\Api;

/**
 * The capabilities the AccessResolver answers `can()` for. Roles are named sets of
 * these; an actor holds an ability on a node when any role that applies there
 * contains it. Each ability owns exactly one {@see Permission} bit, so View and
 * Download are independent (a previewer sees content but cannot take the original)
 * and Manage means managing access, not "everything".
 *
 * @closed The string values are part of the stable contract and never change.
 */
#[Api]
enum Ability: string
{
    case View = 'view';
    case Download = 'download';
    case Comment = 'comment';
    case Edit = 'edit';
    case Create = 'create';
    case Move = 'move';
    case Copy = 'copy';
    case Trash = 'trash';
    case Restore = 'restore';
    case Share = 'share';
    case Manage = 'manage';
    case Delete = 'delete';

    /**
     * This ability's own bit.
     */
    public function bits(): int
    {
        return $this->permission()->value;
    }

    public function permission(): Permission
    {
        return match ($this) {
            self::View => Permission::View,
            self::Download => Permission::Download,
            self::Comment => Permission::Comment,
            self::Edit => Permission::Edit,
            self::Create => Permission::Create,
            self::Move => Permission::Move,
            self::Copy => Permission::Copy,
            self::Trash => Permission::Trash,
            self::Restore => Permission::Restore,
            self::Share => Permission::Share,
            self::Manage => Permission::Manage,
            self::Delete => Permission::Delete,
        };
    }

    /**
     * Whether a bitmask carries this ability.
     */
    public function satisfiedBy(int $bits): bool
    {
        return ($bits & $this->bits()) === $this->bits();
    }

    /**
     * The OR of the given abilities' bits.
     *
     * @param iterable<self> $abilities
     */
    public static function mask(iterable $abilities): int
    {
        $bits = 0;
        foreach ($abilities as $ability) {
            $bits |= $ability->bits();
        }

        return $bits;
    }

    /**
     * The abilities a bitmask carries, in declaration order.
     *
     * @return list<self>
     */
    public static function fromMask(int $bits): array
    {
        return array_values(array_filter(self::cases(), static fn (self $ability): bool => $ability->satisfiedBy($bits)));
    }
}
