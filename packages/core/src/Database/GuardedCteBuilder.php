<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Packman\Attributes\Internal;
use Staudenmeir\LaravelCte\Query\Builder;

/**
 * {@see GuardedBuilder} for models that also run recursive/common table expressions
 * (the node tree).
 */
#[Internal]
final class GuardedCteBuilder extends Builder
{
    use GuardsStatements;
}
