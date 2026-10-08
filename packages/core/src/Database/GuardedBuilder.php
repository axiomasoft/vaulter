<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Illuminate\Database\Query\Builder;

/**
 * The base query builder of Vaulter models: statements that change rows are refused
 * outside a Vaulter operation (see {@see GuardsStatements}).
 *
 * @internal
 */
final class GuardedBuilder extends Builder
{
    use GuardsStatements;
}
