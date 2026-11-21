<?php

declare(strict_types=1);

namespace VaulterRef\Drive;

/** Mirrors Vaulter\Drive\Enums\Access\Ability for the two abilities this flow uses. */
enum Ability: string
{
    case View = 'view';
    case Edit = 'edit';

    /** @return list<string> role keys of packages/drive/config/vaulter-drive.php that carry the ability */
    public function roles(): array
    {
        return match ($this) {
            self::View => ['reader', 'commenter', 'writer', 'organizer', 'owner'],
            self::Edit => ['writer', 'organizer', 'owner'],
        };
    }
}
