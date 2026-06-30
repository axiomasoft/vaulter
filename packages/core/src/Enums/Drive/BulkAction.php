<?php

declare(strict_types=1);

namespace Vaulter\Enums\Drive;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

enum BulkAction: string
{
    use HasLabel;

    #[Label('Delete')]
    case Delete = 'delete';

    #[Label('Restore')]
    case Restore = 'restore';

    #[Label('Move')]
    case Move = 'move';

    #[Label('Delete permanently')]
    case ForceDelete = 'force-delete';

    /** The document ability required to perform this action. */
    public function ability(): string
    {
        return match ($this) {
            self::Delete => 'delete',
            self::Move => 'edit',
            self::Restore, self::ForceDelete => 'manage',
        };
    }

    /** Whether the action operates on trashed (soft-deleted) documents. */
    public function operatesOnTrash(): bool
    {
        return $this === self::Restore || $this === self::ForceDelete;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}
