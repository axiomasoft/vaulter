<?php

declare(strict_types=1);

namespace Vaulter\Drive\Handlers;

use Illuminate\Contracts\Container\Container;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\Handlers\NodePresenter as NodePresenterContract;
use Vaulter\Drive\Contracts\Presentation\KindPresenter;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Handlers\Capability;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\ValueObjects\Handlers\NodePresentation;
use Vaulter\Kernel;

/**
 * A READ-ONLY evaluator that resolves how the client shows a node. A kind whose
 * module contributed a {@see KindPresenter} (slot key = the kind) is presented by
 * it — documents by their document type. Every other node goes through the file
 * handler registry: the descriptor is built from the node (server-trusted mime, never
 * claimed), the registry picks the best handler at the broadest capability (Download —
 * every handler satisfies it, so resolution always yields the most specific match), and
 * that handler's capabilities and viewer describe the file. No persistence, no Request.
 */
#[Internal]
final class NodePresenter implements NodePresenterContract
{
    public function __construct(
        private readonly FileHandlerRegistry $registry,
        private readonly FileDescriptorFactory $descriptors,
        private readonly Container $container,
    ) {}

    public function present(Node|NodeSnapshot $node): NodePresentation
    {
        $presenters = $this->container->make(Kernel::class)->extensions();
        if (in_array($node->kind->value, $presenters->handlerKeys(KindPresenter::class), true)) {
            return $presenters->handler(KindPresenter::class, $node->kind->value)->present($node);
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
