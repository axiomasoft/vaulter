<?php

declare(strict_types=1);

namespace Vaulter\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Staudenmeir\LaravelCte\Eloquent\QueriesExpressions;
use Vaulter\Attributes\Api;
use Vaulter\Database\GuardedBuilder;
use Vaulter\Database\GuardedCteBuilder;
use Vaulter\Database\WriteScope;

/**
 * Refuses writes made outside a Vaulter operation. Every statement that
 * changes rows ends in the model's base query builder, which {@see GuardedBuilder}
 * replaces: a model save, delete or touch and a mass update, delete, insert, upsert or
 * increment are all refused the same way (the Eloquent builder class stays the model's
 * own, so recursive-relationship models keep theirs). No model event is registered:
 * core's batched blob GC treats any `saving` listener as a foreign observer and falls
 * back to a per-row path. A model that also uses
 * HasRecursiveRelationships must pick this method over that trait's:
 * `use GuardsWrites, HasRecursiveRelationships { GuardsWrites::newBaseQueryBuilder insteadof HasRecursiveRelationships; }`.
 *
 * @mixin Model
 */
#[Api]
trait GuardsWrites
{
    #[\Override]
    protected function newBaseQueryBuilder(): Builder
    {
        $connection = $this->getConnection();
        $query = in_array(QueriesExpressions::class, class_uses_recursive($this), true)
            ? new GuardedCteBuilder($connection)
            : new GuardedBuilder($connection);

        return $query->guardedBy($this->vaulterWriteScope());
    }

    /** The scoped service of the running request, job or Octane tick. */
    protected function vaulterWriteScope(): WriteScope
    {
        return app(WriteScope::class);
    }
}
