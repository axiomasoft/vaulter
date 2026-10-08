<?php

declare(strict_types=1);

namespace Vaulter\Handlers;

use Illuminate\Contracts\Container\Container;
use Vaulter\Contracts\Handlers\NodePresenter as NodePresenterContract;
use Vaulter\Contracts\Presentation\KindPresenter;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Handlers\Capability;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Handlers\NodePresentation;

/**
 * A READ-ONLY evaluator that resolves how the client shows a node. A kind whose
 * module tagged a {@see KindPresenter} (`vaulter.kind_presenters`) is presented by
 * it — documents by their document type. Every other node goes through the file
 * handler registry: the descriptor is built from the node (server-trusted mime, never
 * claimed), the registry picks the best handler at the broadest capability (Download —
 * every handler satisfies it, so resolution always yields the most specific match), and
 * that handler's capabilities and viewer describe the file. No persistence, no Request.
 */
final class NodePresenter implements NodePresenterContract
{
    public function __construct(
        private readonly FileHandlerRegistry $registry,
        private readonly FileDescriptorFactory $descriptors,
        private readonly Container $container,
    ) {}

    public function present(Node|NodeSnapshot $node): NodePresentation
    {
        foreach ($this->container->tagged('vaulter.kind_presenters') as $presenter) {
            if ($presenter instanceof KindPresenter && $presenter->kind() === $node->kind) {
                return $presenter->present($node);
            }
        }

        $descriptor = $this->descriptors->forNode($node);
        $handler = $this->registry->resolve($descriptor, Capability::Download);

        return new NodePresentation(
            id: $handler->key(),
            capabilities: $handler->capabilities($descriptor),
            ui: $handler->viewer($descriptor),
        );
    }
}
