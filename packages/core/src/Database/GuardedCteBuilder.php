<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Staudenmeir\LaravelCte\Query\Builder;

/**
 * {@see GuardedBuilder} for models that also run recursive/common table expressions
 * (the node tree).
 *
 * @internal
 */
final class GuardedCteBuilder extends Builder
{
    use GuardsStatements;
}
