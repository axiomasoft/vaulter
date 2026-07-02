<?php

declare(strict_types=1);

namespace Vaulter\Documents\Documents;

use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Documents\FileDescriptor;

/**
 * Builds a {@see FileDescriptor} from a {@see Node} for the registry (§4.5). A
 * READ-ONLY helper (no persistence, no Request) shared by the NodeResource handler
 * descriptor and the export service so node→descriptor mapping lives in ONE place.
 *
 * SECURITY (H.5): the node's stored mime_type is the persisted server value, so it
 * is passed as the DETECTED (trusted) mime — never as the claimed one. A caller that
 * has a fresh server sniff (e.g. just after an upload) passes it as $detectedMime to
 * override; the client-claimed Content-Type is never routed on.
 */
final class DocumentFileDescriptorFactory
{
    public function forNode(Node $node, ?string $detectedMime = null): FileDescriptor
    {
        return FileDescriptor::make(
            nodeId: $node->id,
            filename: $node->name,
            extension: $node->extension,
            // The node's persisted mime is a server value → the trusted detected mime.
            detectedMime: $detectedMime ?? $node->mime_type,
            size: $node->size_bytes,
        );
    }
}
