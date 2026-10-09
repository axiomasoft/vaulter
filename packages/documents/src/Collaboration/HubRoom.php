<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Vaulter\Documents\Contracts\DocumentType;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;

/**
 * A hub call's room as it stands now: the live document behind the token, the
 * caller's current highest ability on it (null: not even View), whether its drive is
 * active (an archived drive is read-only) and whether the room may write at this moment.
 *
 * @internal
 */
final readonly class HubRoom
{
    public function __construct(
        public Node $node,
        public Document $document,
        public ?DocumentType $type,
        public ?Ability $ability,
        public bool $writable,
        public bool $driveActive,
        public string $userId,
    ) {}

    public function mode(): string
    {
        return $this->writable ? 'rw' : 'ro';
    }
}
