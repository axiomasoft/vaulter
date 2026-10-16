<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Events;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;
use Vaulter\Support\Identity;

/**
 * The morph an activity row points at. The feed resolves visibility through it, so
 * events keep the target they always had (usually the node).
 */
#[Internal]
final readonly class ActivityTarget
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function of(Model $model): self
    {
        return new self(type: $model->getMorphClass(), id: Identity::stringify($model->getKey()));
    }
}
