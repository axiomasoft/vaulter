<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use InvalidArgumentException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Drive\NodeUserStateRepository;

/**
 * Star or unstar a node for the acting user (PLAN2.D40). A star is personal: it
 * takes no tree lock and changes neither the node's sequence nor its timestamps.
 *
 * Operations authorize (View) before this command.
 */
final readonly class StarNodeAction
{
    public function __construct(private NodeUserStateRepository $states, private VaulterDatabase $database) {}

    public function execute(OperationContext $context, Node $node, bool $starred): Node
    {
        if ($context->actor->isSystem()) {
            throw new InvalidArgumentException('[Vaulter] Stars are personal; a system actor cannot star a node.');
        }

        $this->database->write(function () use ($node, $context, $starred): void {
            $starred
                ? $this->states->star(node: $node, actor: $context->actor->ref())
                : $this->states->unstar(node: $node, actor: $context->actor->ref());
        });

        return $node;
    }
}
