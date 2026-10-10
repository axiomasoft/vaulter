<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Models\Drive\Node;

/**
 * Restore and permanent delete only operate on a trashed node: deleting a live node
 * must go through the trash so it stays recoverable until it is purged.
 */
final class NodeNotTrashedException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private const string PROBLEM_CODE = 'node_not_trashed';

    private const int HTTP_STATUS = 409;

    public static function forNode(Node $node): self
    {
        return new self(
            "[Vaulter] Node [{$node->id}] is not in the trash. "
            .'Why: restore and permanent delete only apply to trashed nodes. '
            .'Fix: trash the node first. '
            .'Docs: '.self::docs('node-not-trashed'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }
}
