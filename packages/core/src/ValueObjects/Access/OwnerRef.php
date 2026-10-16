<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Access;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vaulter\Attributes\Api;

#[Api]
final readonly class OwnerRef
{
    public function __construct(public string $type, public int|string $id)
    {
        if ($type === '' || $id === '') {
            throw new InvalidArgumentException('Owner identity cannot be empty.');
        }
    }

    public static function ofModel(Model $owner): self
    {
        $id = $owner->getKey();
        if (!is_int($id) && !is_string($id)) {
            throw new InvalidArgumentException('Owner requires a model key.');
        }

        return new self(type: $owner->getMorphClass(), id: $id);
    }
}
