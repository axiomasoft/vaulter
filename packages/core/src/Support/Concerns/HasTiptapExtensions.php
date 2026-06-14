<?php

declare(strict_types=1);

namespace DocVault\Support\Concerns;

use DocVault\Attributes\TiptapExtensions;
use DocVault\Support\EnumCaseAttributeResolver;

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
