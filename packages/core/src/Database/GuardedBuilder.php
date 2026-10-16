<?php

declare(strict_types=1);

namespace Vaulter\Database;

use Illuminate\Database\Query\Builder;
use Vaulter\Attributes\Internal;

/**
 * The base query builder of Vaulter models: statements that change rows are refused
 * outside a Vaulter operation (see {@see GuardsStatements}).
 */
#[Internal]
final class GuardedBuilder extends Builder
{
    use GuardsStatements;
}
