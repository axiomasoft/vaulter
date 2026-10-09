<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

/**
 * A subtree is deeper than the bounded traversal can read, so the set an operation
 * would act on is not provably complete. It stops before any write rather than copy,
 * trash or purge only part of the tree.
 */
final class TreeTraversalIncompleteException extends VaulterException implements ProblemException
{
    public static function forBound(int $bound): self
    {
        return new self('[Vaulter] The folder tree is deeper than '.$bound.' levels below the node. '
            .'Why: the operation cannot prove it sees every descendant, and it never acts on part of a tree. '
            .'Fix: act on a shallower folder first. Docs: '.self::docs('tree-traversal-incomplete'));
    }

    public function problemCode(): string
    {
        return 'tree_traversal_incomplete';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
