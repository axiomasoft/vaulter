<?php

declare(strict_types=1);

namespace Vaulter\Enums;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * Ordered access levels. Higher rank implies all lower-ranked abilities.
 */
enum PermissionLevel: string
{
    use HasLabel;

    #[Label('Can view')]
    case View = 'view';

    #[Label('Can comment')]
    case Comment = 'comment';

    #[Label('Can edit')]
    case Edit = 'edit';

    #[Label('Can manage')]
    case Manage = 'manage';

    public function rank(): int
    {
        return match ($this) {
            self::View => 1,
            self::Comment => 2,
            self::Edit => 3,
            self::Manage => 4,
        };
    }

    /**
     * Does this level grant at least the abilities of $other?
     */
    public function grants(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
