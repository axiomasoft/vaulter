<?php

declare(strict_types=1);

namespace DocVault\Support\Concerns;

use DocVault\Attributes\Label;
use DocVault\Support\EnumCaseAttributeResolver;
use Illuminate\Support\Str;

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
