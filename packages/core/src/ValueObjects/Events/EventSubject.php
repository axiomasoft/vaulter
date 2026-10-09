<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Events;

use InvalidArgumentException;
use Vaulter\Support\Identity;

/**
 * What an event is about: the resource a consumer fetches to see the new state.
 * The id is a string whatever the id strategy.
 */
final readonly class EventSubject
{
    public const array TYPES = ['drive', 'node', 'comment', 'share', 'upload'];

    public string $id;

    public function __construct(
        public string $type,
        mixed $id,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('[Vaulter] Unknown event subject type ['.$type.'].');
        }
        $this->id = Identity::stringify($id);
        if ($this->id === '') {
            throw new InvalidArgumentException('[Vaulter] An event subject needs an id.');
        }
    }
}
