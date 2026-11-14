<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories;

use Staudenmeir\LaravelAdjacencyList\Query\Grammars\SQLiteGrammar;
use Vaulter\Attributes\Internal;

#[Internal]
final class NodeSqliteGrammar extends SQLiteGrammar
{
    public function compileCycleDetection(string $localKey, string $path): string
    {
        $localKey = $this->wrap($localKey);
        $path = $this->wrap($path);

        // The first component starts at position 1. An unanchored match mistakes
        // the suffix 100 in the path 2100.50 for an already visited node.
        return "instr($path, $localKey || ?) = 1 or instr($path, ? || $localKey || ?) > 0";
    }
}
