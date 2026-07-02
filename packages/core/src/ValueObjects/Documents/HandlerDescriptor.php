<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\ValueObjects;

/**
 * The resolved-handler descriptor embedded in a node payload (§4.5 / §5 / M4a): the
 * handler id + its capabilities + its front-end mount descriptor, so the Vue client
 * knows which editor/preview/download surface to mount for the node WITHOUT
 * re-running mime logic on the client. Immutable.
 */
final readonly class HandlerDescriptor
{
    public function __construct(
        public string $id,
        public DocumentCapabilities $capabilities,
        public HandlerUi $ui,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'capabilities' => $this->capabilities->toArray(),
            'ui' => $this->ui->toArray(),
        ];
    }
}
