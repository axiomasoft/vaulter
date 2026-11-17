<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Nodes;

use InvalidArgumentException;
use Packman\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\NodeUserStateRepository;

/**
 * Star or unstar a node for the acting user. A star is personal: it
 * takes no tree lock and changes neither the node's sequence nor its timestamps.
 *
 * Operations authorize (View) before this command.
 */
#[Internal]
final readonly class StarNodeAction
{
    public function __construct(private NodeUserStateRepository $states, private VaulterDatabase $database) {}

    public function execute(DriveContext $context, Node $node, bool $starred): Node
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
