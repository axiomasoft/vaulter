<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Brings a document's working state up to its collaboration log: the realtime transport compacts the log of the
 * marker's generation into a checkpoint published through {@see CollaborativeState}. The capability is optional
 * (DocumentsModule); without a provider a pending document answers 409 `document_state_pending`.
 *
 * Documents calls drain() outside any transaction, before a REST replace, restore, content read, export, and then
 * locks the document again and reads the marker itself. drain() waits at most the transport's own deadline and returns
 * when the marker is covered, when the deadline passed or when the compaction cannot run (the hub is unreachable): the
 * outcome is the marker documents reads afterwards, never the return.
 */
#[Spi(SpiKind::Implement)]
interface PendingStateDrainer
{
    public function protocol(): string;

    public function drain(PendingMarker $marker): void;
}
