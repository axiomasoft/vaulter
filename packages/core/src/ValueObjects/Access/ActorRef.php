<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Access;

use InvalidArgumentException;

final readonly class ActorRef
{
    public const string SYSTEM_TYPE = 'vaulter:system';

    public ?string $reason;

    public function __construct(
        public ?string $type,
        public int|string|null $id,
        ?string $reason = null,
    ) {
        $reason = $reason === null ? null : trim($reason);
        if ($type === self::SYSTEM_TYPE && ($id !== null || $reason === null || $reason === '' || strlen($reason) > 191)) {
            throw new InvalidArgumentException('[Vaulter] A system actor requires a nonempty reason of at most 191 bytes and no user ID.');
        }
        if ($type !== self::SYSTEM_TYPE && $reason !== null) {
            throw new InvalidArgumentException('[Vaulter] Only system actors have a reason.');
        }
        if (($type === null) !== ($id === null) && $type !== self::SYSTEM_TYPE) {
            throw new InvalidArgumentException('[Vaulter] Actor type and user ID must be supplied together.');
        }
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('[Vaulter] Actor identity cannot be empty.');
        }
        $this->reason = $reason;
    }
}
