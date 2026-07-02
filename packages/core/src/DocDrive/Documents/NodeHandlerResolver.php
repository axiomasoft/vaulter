<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\Documents;

use Vaulter\DocDrive\Enums\Capability;
use Vaulter\DocDrive\ValueObjects\HandlerDescriptor;
use Vaulter\Models\Drive\Node;

/**
 * A READ-ONLY evaluator that resolves the HANDLER DESCRIPTOR for a node (§4.5 / §5 /
 * M4a) — the (id + capabilities + ui) bundle the NodeResource embeds and the
 * GET .../handler endpoint returns, so the Vue client routes the node to the right
 * editor/preview/download surface.
 *
 * It builds the FileDescriptor from the node (server-trusted mime, never claimed),
 * asks the registry for the best handler at the broadest capability (Download — every
 * handler satisfies it, so resolution always yields the most specific match rather
 * than narrowing to one capability), then reads that handler's capabilities + ui for
 * the file. No persistence, no Request — a pure read-side mapping.
 */
final class NodeHandlerResolver
{
    public function __construct(
        private readonly DocumentHandlerRegistry $registry,
        private readonly DocumentFileDescriptorFactory $descriptors,
    ) {}

    public function describe(Node $node, ?string $detectedMime = null): HandlerDescriptor
    {
        $descriptor = $this->descriptors->forNode($node, detectedMime: $detectedMime);

        // Resolve at the broadest capability so the MOST SPECIFIC matching handler
        // wins (every handler satisfies Download); the descriptor then reports that
        // handler's full capability set for the client to branch on.
        $handler = $this->registry->resolve($descriptor, Capability::Download);

        return new HandlerDescriptor(
            id: $handler->id(),
            capabilities: $handler->capabilities($descriptor),
            ui: $handler->ui($descriptor),
        );
    }
}
