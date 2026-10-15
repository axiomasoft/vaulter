<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Handlers;

use Vaulter\Attributes\Api;

/**
 * How the client should show a node: which file handler serves it (null for a
 * document, whose presentation comes from its document type), the document type key
 * and whether that type is installed, the capabilities, and the viewer to mount.
 * Only keys and scalars, so it can be serialized as is.
 */
#[Api]
final readonly class NodePresentation
{
    public function __construct(
        public ?string $id,
        public ContentCapabilities $capabilities,
        public ViewerDescriptor $ui,
        public ?string $documentType = null,
        public bool $typeAvailable = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->documentType,
            'type_available' => $this->typeAvailable,
            'capabilities' => $this->capabilities->toArray(),
            'ui' => $this->ui->toArray(),
        ];
    }
}
