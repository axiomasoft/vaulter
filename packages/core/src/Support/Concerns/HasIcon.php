<?php

declare(strict_types=1);

namespace Vaulter\Support\Concerns;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Icon;
use Vaulter\Support\EnumCaseAttributeResolver;

/**
 * @phpstan-require-implements \UnitEnum
 */
#[Api]
trait HasIcon
{
    public function icon(): ?string
    {
        $icon = app(EnumCaseAttributeResolver::class)->forCase($this, Icon::class);

        return $icon instanceof Icon ? $icon->value : null;
    }
}
