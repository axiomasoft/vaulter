<?php

declare(strict_types=1);

namespace Vaulter\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use Override;
use Packman\Attributes\Api;

/**
 * A Vaulter model whose type is stored in a polymorphic column (the target of an activity row) is stored under a
 * constant alias, never under its class name: moving the class between packages changes no stored row, and a host's
 * morph map or `enforceMorphMap()` neither renames nor refuses it. The alias is the `MORPH_ALIAS` constant of the
 * model, `vaulter.<entity>`; a subclass a host configures in `vaulter-drive.models.*` inherits it.
 *
 * @phpstan-require-extends Model
 */
#[Api]
trait HasStableMorphAlias
{
    #[Override]
    public function getMorphClass(): string
    {
        return static::MORPH_ALIAS;
    }
}
