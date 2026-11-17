<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts\Handlers;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\ValueObjects\Handlers\NodePresentation;

/**
 * Resolves the format-handler descriptor for a node — the (id +
 * capabilities + ui) bundle {@see NodeResource} embeds
 * and the GET .../handler endpoint returns, so the Vue client routes the node to the
 * right editor/preview/download surface without re-running mime logic client-side.
 *
 * Both the contract and the concrete resolver (`Vaulter\Drive\Handlers\NodePresenter`,
 * backed by the core-owned `Vaulter\Drive\Handlers\FileHandlerRegistry`) are core-owned:
 * a drive-only host with no
 * vaulter-documents installed still resolves a real preview/download descriptor
 * through this seam, never null). {@see NodeResource::handler()} resolves it
 * unconditionally for every leaf node. A folder node is never passed here — only
 * called for leaf (file/document) nodes. vaulter-documents (or another package)
 * contributes richer file formats by tagging handlers into the same registry, and
 * presents a whole node kind through a tagged KindPresenter — it never rebinds this seam.
 */
#[Spi(SpiKind::Implement)]
interface NodePresenter
{
    public function present(NodeSnapshot $node): NodePresentation;
}
