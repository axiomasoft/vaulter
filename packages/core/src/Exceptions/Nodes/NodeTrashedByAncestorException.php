<?php

declare(strict_types=1);

namespace Vaulter\Exceptions\Nodes;

use Vaulter\Exceptions\Concerns\ProvidesProblemDetails;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Models\Drive\Node;

/** A node trashed together with a folder is restored through that folder (PLAN2.D37). */
final class NodeTrashedByAncestorException extends VaulterException implements ProblemException
{
    use ProvidesProblemDetails;

    private function __construct(string $message, private readonly int|string $trashRootId)
    {
        parent::__construct($message);
    }

    public static function forNode(Node $node, int|string $trashRootId): self
    {
        return new self(
            "[Vaulter] Node [{$node->id}] was trashed with [{$trashRootId}]. "
            .'Why: only a trash root can be restored; its members come back with it. '
            ."Fix: restore the trash root [{$trashRootId}] or purge it. "
            .'Docs: '.self::docs('node-trashed-by-ancestor'),
            $trashRootId,
        );
    }

    public function trashRootId(): int|string
    {
        return $this->trashRootId;
    }

    public function problemCode(): string
    {
        return 'node_trashed_by_ancestor';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    /** @return array<string, mixed> */
    public function problemDetails(): array
    {
        return ['context' => ['trash_root_id' => (string) $this->trashRootId]];
    }
}
