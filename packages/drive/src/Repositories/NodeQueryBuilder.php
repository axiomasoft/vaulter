<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Packman\Attributes\Internal;
use Staudenmeir\LaravelAdjacencyList\Eloquent\Builder;
use Staudenmeir\LaravelAdjacencyList\Query\Grammars\ExpressionGrammar;
use Vaulter\Drive\Models\Node;

/**
 * @template TModel of Node
 *
 * @extends Builder<TModel>
 */
#[Internal]
final class NodeQueryBuilder extends Builder
{
    /** @param TModel $model */
    public function __construct(QueryBuilder $query, Node $model)
    {
        parent::__construct($query);
        $this->setModel($model);
    }

    public function getExpressionGrammar(): ExpressionGrammar
    {
        $connection = $this->getModel()->getConnection();

        return $connection->getDriverName() === 'sqlite'
            ? new NodeSqliteGrammar($connection, $this->getModel())
            : parent::getExpressionGrammar();
    }
}
