<?php

declare(strict_types=1);

namespace Vaulter\Modules;

use Packman\Attributes\Internal;

/**
 * The outcome of a compilation that failed: every reason, and nothing activated.
 */
#[Internal]
final readonly class InvalidModuleGraph
{
    /** @param list<string> $reasons */
    public function __construct(public array $reasons) {}
}
