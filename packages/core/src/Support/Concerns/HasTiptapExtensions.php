<?php

declare(strict_types=1);

namespace Vaulter\Support\Concerns;

use Vaulter\Attributes\TiptapExtensions;
use Vaulter\Support\EnumCaseAttributeResolver;

/**
 * @phpstan-require-implements \UnitEnum
 */
trait HasTiptapExtensions
{
    /**
     * @return list<string>
     */
    public function tiptapExtensions(): array
    {
        $extensions = EnumCaseAttributeResolver::forCase($this, TiptapExtensions::class);

        return $extensions instanceof TiptapExtensions ? $extensions->names : [];
    }
}
