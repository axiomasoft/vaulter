<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Documents;

use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Documents\HandlerDescriptor;

/**
 * Resolves the format-handler descriptor for a node (§4.5 / §5 / M4a) — the (id +
 * capabilities + ui) bundle {@see NodeResource} embeds
 * and the GET .../handler endpoint returns, so the Vue client routes the node to the
 * right editor/preview/download surface without re-running mime logic client-side.
 *
 * The contract lives in core (the kernel owns Node and the NodeResource that embeds
 * the descriptor) while the concrete resolver ships in vaulter-documents (P2 #5 /
 * ADR-0015): core defines the seam, the documents package binds the implementation
 * (its DocumentHandlerRegistry + the registered handlers). A host that has not
 * installed vaulter-documents still type-hints against this interface; a DIRECT
 * `app(NodeHandlerResolver::class)` resolution fails loudly with an install hint
 * (a MissingPackageException, F-P3.3-02). The package's OWN consumer — {@see
 * NodeResource::handler()} — does NOT hit that path: it explicitly checks
 * `app()->bound(NodeHandlerResolver::class)` first and degrades to null when
 * vaulter-documents is absent (Drive without documents still lists/serialises file
 * nodes through the same resource). A folder node is never passed here —
 * NodeResource only calls this for leaf (file/document) nodes.
 */
interface NodeHandlerResolver
{
    public function describe(Node $node, ?string $detectedMime = null): HandlerDescriptor;
}
