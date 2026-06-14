<?php

declare(strict_types=1);

namespace DocVault\Support\Concerns;

use DocVault\Attributes\Icon;
use DocVault\Support\EnumCaseAttributeResolver;

/**
 * @phpstan-require-implements \UnitEnum
 */
trait HasIcon
{
    public function icon(): ?string
    {
        $icon = EnumCaseAttributeResolver::forCase($this, Icon::class);

        return $icon instanceof Icon ? $icon->value : null;
    }
}
