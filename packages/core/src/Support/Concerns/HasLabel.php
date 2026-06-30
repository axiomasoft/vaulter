<?php

declare(strict_types=1);

namespace Vaulter\Support\Concerns;

use Illuminate\Support\Str;
use Vaulter\Attributes\Label;
use Vaulter\Support\EnumCaseAttributeResolver;

/**
 * @phpstan-require-implements \UnitEnum
 */
trait HasLabel
{
    public function label(): string
    {
        $label = EnumCaseAttributeResolver::forCase($this, Label::class);

        return $label instanceof Label ? $label->value : Str::headline($this->name);
    }
}
