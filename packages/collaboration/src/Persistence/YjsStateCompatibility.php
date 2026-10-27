<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Persistence;

use Vaulter\Attributes\Internal;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\State\CollaborationStateBinding;
use Vaulter\Documents\Types\DocumentTypeRegistry;

/** Compatibility of this concrete Yjs adapter; keyed routing belongs to the driver router. */
#[Internal]
final readonly class YjsStateCompatibility
{
    public function __construct(private DocumentTypeRegistry $types) {}

    public function assert(string $documentType, ?CollaborationStateBinding $binding): void
    {
        $type = $this->types->find($documentType);
        if (!$type instanceof SupportsCollaboration || $binding === null || $binding->protocol !== 'yjs'
            || $binding->stateFormat !== 'yjs-update-v1' || $type->collaborationProtocol() !== $binding->protocol
            || $type->collaborationStateFormat() !== $binding->stateFormat || $type->schemaVersion() !== $binding->schemaVersion) {
            throw new CollaborationUnavailableException('unsupported_binding', $documentType);
        }
    }
}
