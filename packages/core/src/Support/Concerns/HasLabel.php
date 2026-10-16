<?php

declare(strict_types=1);

namespace Vaulter\Support\Concerns;

use Illuminate\Support\Str;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Label;
use Vaulter\Support\EnumCaseAttributeResolver;

/**
 * @phpstan-require-implements \UnitEnum
 */
#[Api]
trait HasLabel
{
    public function label(): string
    {
        $label = app(EnumCaseAttributeResolver::class)->forCase($this, Label::class);

        return $label instanceof Label ? $label->value : Str::headline($this->name);
    }
}
