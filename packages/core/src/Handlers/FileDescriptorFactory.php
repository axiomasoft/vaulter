<?php

declare(strict_types=1);

namespace Vaulter\Handlers;

use Vaulter\Attributes\Internal;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Models\Drive\Node;
use Vaulter\ValueObjects\Handlers\FileDescriptor;

/**
 * Builds a {@see FileDescriptor} from a {@see Node} for the registry. A
 * READ-ONLY helper (no persistence, no Request) shared by the NodeResource handler
 * descriptor and the export service so node→descriptor mapping lives in ONE place.
 *
 * SECURITY: the node's stored mime_type is the persisted server value, so it
 * is passed as the DETECTED (trusted) mime — never as the claimed one. A caller that
 * has a fresh server sniff (e.g. just after an upload) passes it as $detectedMime to
 * override; the client-claimed Content-Type is never routed on.
 */
#[Internal]
final class FileDescriptorFactory
{
    public function forNode(Node|NodeSnapshot $node, ?string $detectedMime = null): FileDescriptor
    {
        if ($node instanceof NodeSnapshot) {
            return FileDescriptor::make(
                nodeId: $node->id,
                filename: $node->name,
                extension: $node->extension,
                detectedMime: $detectedMime ?? $node->mimeType,
                size: $node->sizeBytes,
            );
        }

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
